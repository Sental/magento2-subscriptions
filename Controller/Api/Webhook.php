<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Subscriptions\Controller\Api;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NotFoundException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Subscription;
use Mollie\Payment\Api\MollieCustomerRepositoryInterface;
use Mollie\Payment\Logger\MollieLogger;
use Mollie\Payment\Model\Mollie;
use Mollie\Payment\Service\Order\SendOrderEmails;
use Mollie\Subscriptions\Config;

class Webhook extends Action implements CsrfAwareActionInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Mollie
     */
    private $mollie;

    /**
     * @var MollieCustomerRepositoryInterface
     */
    private $mollieCustomerRepository;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var AddressInterfaceFactory
     */
    private $addressFactory;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var MollieLogger
     */
    private $mollieLogger;

    /**
     * @var SendOrderEmails
     */
    private $sendOrderEmails;

    public function __construct(
        Context $context,
        Config $config,
        Mollie $mollie,
        MollieCustomerRepositoryInterface $mollieCustomerRepository,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository,
        CustomerRepositoryInterface $customerRepository,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        OrderRepositoryInterface $orderRepository,
        MollieLogger $mollieLogger,
        SendOrderEmails $sendOrderEmails
    ) {
        parent::__construct($context);

        $this->config = $config;
        $this->mollie = $mollie;
        $this->mollieCustomerRepository = $mollieCustomerRepository;
        $this->cartManagement = $cartManagement;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->customerRepository = $customerRepository;
        $this->addressFactory = $addressFactory;
        $this->addressRepository = $addressRepository;
        $this->orderRepository = $orderRepository;
        $this->mollieLogger = $mollieLogger;
        $this->sendOrderEmails = $sendOrderEmails;
    }

    public function execute()
    {
        if ($this->config->disableNewOrderConfirmation()) {
            $this->sendOrderEmails->disableOrderConfirmationSending();
        }

        $id = $this->getRequest()->getParam('id');
        if ($orders = $this->mollie->getOrderIdsByTransactionId($id)) {
            foreach ($orders as $orderId) {
                $this->mollie->processTransaction($orderId, 'webhook');
            }

            return $this->returnOkResponse();
        }

        try {
            $api = $this->mollie->getMollieApi();
            $molliePayment = $api->payments->get($id);
            $subscription = $api->subscriptions->getForId($molliePayment->customerId, $molliePayment->subscriptionId);

            $customerId = $this->mollieCustomerRepository->getByMollieCustomerId($molliePayment->customerId)->getCustomerId();
            $customer = $this->customerRepository->getById($customerId);

            $cart = $this->getCart($customer);
            $this->addProduct($api, $molliePayment, $cart);

            $cart->setBillingAddress($this->formatAddress($this->addressRepository->getById($customer->getDefaultBilling())));
            $this->setShippingAddress($customer, $cart);

            $cart->getPayment()->addData(['method' => 'mollie_methods_' . $molliePayment->method]);

            $cart->collectTotals();
            $this->cartRepository->save($cart);

            $order = $this->cartManagement->submit($cart);
            $order->setMollieTransactionId($molliePayment->id);
            $order->getPayment()->setAdditionalInformation('subscription_created', $subscription->createdAt);
            $this->orderRepository->save($order);

            $this->mollie->processTransactionForOrder($order, 'webhook');
            return $this->returnOkResponse();
        } catch (ApiException $exception) {
            $this->mollieLogger->addInfoLog('ApiException occured while checking transaction', [
                'id' => $id,
                'exception' => $exception->__toString()
            ]);

            throw new NotFoundException(__('Please check the Mollie logs for more information'));
        }
    }

    private function formatAddress(\Magento\Customer\Api\Data\AddressInterface $customerAddress): AddressInterface
    {
        $address = $this->addressFactory->create();
        $address->setFirstname($customerAddress->getFirstName());
        $address->setMiddlename($customerAddress->getMiddlename());
        $address->setLastname($customerAddress->getLastname());
        $address->setStreet($customerAddress->getStreet());
        $address->setPostcode($customerAddress->getPostcode());
        $address->setCity($customerAddress->getCity());
        $address->setCountryId($customerAddress->getCountryId());
        $address->setCompany($customerAddress->getCompany());
        $address->setTelephone($customerAddress->getTelephone());
        $address->setFax($customerAddress->getFax());
        $address->setVatId($customerAddress->getVatId());
        $address->setSuffix($customerAddress->getSuffix());
        $address->setPrefix($customerAddress->getPrefix());

        return $address;
    }

    private function addProduct(MollieApiClient $api, Payment $mollieOrder, CartInterface $cart)
    {
        /** @var Subscription $subscription */
        $subscription = $api->performHttpCallToFullUrl(MollieApiClient::HTTP_GET, $mollieOrder->_links->subscription->href);
        $sku = $subscription->metadata->sku;
        $product = $this->productRepository->get($sku);

        $cart->addProduct($product);
    }

    private function setShippingAddress(CustomerInterface $customer, CartInterface $cart)
    {
        $shippingAddress = $this->formatAddress($this->addressRepository->getById($customer->getDefaultShipping()));
        $cart->setShippingAddress($shippingAddress);

        $shippingAddress = $cart->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();
        $shippingAddress->setShippingMethod($this->config->getShippingMethod());
    }

    private function getCart(CustomerInterface $customer): CartInterface
    {
        $cartId = $this->cartManagement->createEmptyCart();
        $cart = $this->cartRepository->get($cartId);
        $cart->setStoreId($customer->getStoreId());
        $cart->setCustomer($customer);
        $cart->setCustomerIsGuest(0);

        return $cart;
    }

    private function returnOkResponse()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('content-type', 'text/plain');
        $result->setContents('OK');
        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
