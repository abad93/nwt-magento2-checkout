<?php


namespace Svea\Checkout\Model;

use Svea\Checkout\Model\Client\ClientException;
use Svea\Checkout\Model\Client\DTO\GetOrderResponse;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

class Checkout extends \Magento\Checkout\Model\Type\Onepage
{

    protected $_paymentMethod = 'sveacheckout';

    /** @var CheckoutContext $context */
    protected $context;

    protected $sveaOrderOrderHandler;

    protected $_allowedCountries;

    protected $_doNotMarkCartDirty  = false;


    /**
     * @param CheckoutContext $context
     */
    public function setCheckoutContext(CheckoutContext $context)
    {
        $this->context = $context;
    }

    /**
     * @return \Svea\Checkout\Helper\Data
     */
    public function getHelper()
    {
        return $this->context->getHelper();
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->_logger;
    }

    /**
     * @param bool $reloadIfCurrencyChanged
     * @return $this
     * @throws CheckoutException
     * @throws LocalizedException
     */
    public function initCheckout($reloadIfCurrencyChanged = true)
    {
        if (!($this->context instanceof CheckoutContext)) {
            throw new \Exception("Context must set first!");
        }


        $quote  = $this->getQuote();
        $this->checkCart();

        //init checkout
        $customer = $this->getCustomerSession();
        if($customer->getId()) {
            //$this->_logger->info(__("Set customer %1",$customer->getId()));
            $quote->assignCustomer($customer->getCustomerDataObject()); //this will set also primary billing/shipping address as billing address
            //$quote->setCustomer($customer->getCustomerDataObject());
        }

        $allowCountries = $this->getAllowedCountries(); //this is not null (it is checked into $this->checkCart())
        $billingAddress  = $quote->getBillingAddress();
        if($quote->isVirtual()) {
            $shippingAddress = $billingAddress;
        } else {
            $shippingAddress = $quote->getShippingAddress();
        }

        if (!$shippingAddress->getCountryId()) {
            $this->_logger->info(__("No country set, change to %1",$allowCountries[0]));
            $this->changeCountry($allowCountries[0],$save = false);
        } elseif(!in_array($shippingAddress->getCountryId(),$allowCountries)) {
            $this->_logger->info(__("Wrong country set %1, change to %2",$shippingAddress->getCountryId(),$allowCountries[0]));
            $this->messageManager->addNoticeMessage(__("Svea checkout is not available for %1, country was changed to %2.",$shippingAddress->getCountryId(),$allowCountries[0]));
            $this->changeCountry($allowCountries[0],$save = false);
        }

        if(!$billingAddress->getCountryId() || $billingAddress->getCountryId() != $shippingAddress->getCountryId()) {
            //$this->_logger->info(__("Billing country [%1] != shipping [%2]",$billingAddress->getCountryId(),$shippingAddress->getCountryId()));
            $this->changeCountry($shippingAddress->getCountryId(),$save = false);
        }


        $currencyChanged = $this->checkAndChangeCurrency();
        $payment = $quote->getPayment();


        //force payment method  to our payment method
        $paymentMethod     = $payment->getMethod();

        $shipPaymentMethod = $shippingAddress->getPaymentMethod();

        if(!$paymentMethod || !$shipPaymentMethod || $paymentMethod != $this->_paymentMethod || $shipPaymentMethod != $paymentMethod) {
            $payment->unsMethodInstance()->setMethod($this->_paymentMethod);
            $quote->setTotalsCollectedFlag(false);
            //if quote is virtual, shipping is set as billing (see above)
            //setCollectShippingRates because in onepagecheckout is affirmed that shipping rates could depends by payment method
            $shippingAddress->setPaymentMethod($payment->getMethod())->setCollectShippingRates(true);
        }


        //TODO: ADD MINIMUM AOUNT TEST here

        // do not set shipping method
     //   $method = $this->checkAndChangeShippingMethod();

        try {
            $quote->setTotalsCollectedFlag(false)->collectTotals()->save(); //REQUIRED (maybe shipping amount was changed)
        } catch (\Exception $e) {
            // do nothing
        }


        $billingAddress->save();
        $shippingAddress->save();

        $this->totalsCollector->collectAddressTotals($quote, $shippingAddress);
        $this->totalsCollector->collectQuoteTotals($quote);

        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        if($currencyChanged && $reloadIfCurrencyChanged) {
            //not needed
            $this->throwReloadException(__('Checkout was reloaded.'));
        }

        /*
        if($method === false) {
            throw new LocalizedException(__('No shipping method'));
        }
        */

        return $this;
    }

    /**
     * @return string
     */
    public function getQuoteSignature()
    {
        return $this->getHelper()->generateHashSignatureByQuote($this->getQuote());
    }

    /**
     * @return bool
     * @throws CheckoutException
     */
    public function checkCart() {
        $quote = $this->getQuote();

        //@see OnePage::initCheckout
        if($quote->getIsMultiShipping()) {
            $quote->setIsMultiShipping(false)->removeAllAddresses();
        }

        if(!$this->getHelper()->isEnabled()) {
            $this->throwRedirectToCartException(__('The Svea Checkout is not enabled, please use an alternative checkout method.'));
        }

        if(!$this->getAllowedCountries()) {
            $this->throwRedirectToCartException(__('The Svea Checkout is NOT available (no allowed country), please use an alternative checkout method.'));
        }

        if (!$quote->hasItems()) {
            $this->throwRedirectToCartException(__('There are no items in your cart.'));
        }

        if ($quote->getHasError()) {
            $this->throwRedirectToCartException(__('The cart contains errors.'));
        }

        if (!$quote->validateMinimumAmount()) {
            $error =$this->getHelper()->getStoreConfig('sales/minimum_order/error_message');
            if (!$error) {
                $error = __('Subtotal must exceed minimum order amount.');
            }

            $this->throwRedirectToCartException($error);
        }
      
        return true;
    }


    /**
     * @return bool
     * @throws LocalizedException
     */
    public function checkAndChangeCurrency()
    {
        $quote  = $this->getQuote();
        $country    = $quote->getBillingAddress()->getCountryId();

        if (!$country) {
            throw new LocalizedException(__('Country is not set.')); // this shouldn't happen anyways
        }

        $quote->setTotalsCollectedFlag(false);
        if (!$quote->isVirtual() && $quote->getShippingAddress()) {
            $quote->getShippingAddress()->setCollectShippingRates(true);
        }

        return false;
    }


    /**
     * @param $country
     * @param bool $saveQuote
     * @throws LocalizedException
     */
    public function changeCountry($country, $saveQuote = false) {

        $allowCountries = $this->getAllowedCountries();
        if(!$country || !in_array($country,$allowCountries)) {
            throw new LocalizedException(__('Invalid country (%1)',$country));
        }

        $blankAddress = $this->getBlankAddress($country);
        $quote        = $this->getQuote();

        $quote->getBillingAddress()->addData($blankAddress);
        if(!$quote->isVirtual()) {
            $quote->getShippingAddress()->addData($blankAddress)->setCollectShippingRates(true);
        }
        if($saveQuote) {
            $this->checkAndChangeCurrency();
            $quote->collectTotals()->save();
        }
    }


    /**
     * @param $country
     * @return array
     */
    public function getBlankAddress($country) {
        $blankAddress = array(
            'customer_address_id'=>0,
            'save_in_address_book'=>0,
            'same_as_billing'=>0,
            'street'=>'',
            'city'=>'',
            'postcode'=>'',
            'region_id'=>'',
            'country_id'=>$country
        );
        return $blankAddress;
    }


    /**
     * @return array
     */
    public function getAllowedCountries() {
        if(is_null($this->_allowedCountries)) {
            $this->_allowedCountries = ["SE", "DK", "NO"]; // todo get from settings
        }

        return $this->_allowedCountries;
    }


    /**
     * @return $this
     * @throws LocalizedException
     */
    public function initSveaCheckout()
    {
        $quote       = $this->getQuote();
        $sveaHandler = $this->getSveaPaymentHandler()->assignQuote($quote); // this will also validate the quote!

        // a signature is a md5 hashed value of the customer quote. Using this we can store the hash in session and compare the values
        $newSignature = $this->getHelper()->generateHashSignatureByQuote($quote);

        // check if we already have started a payment flow with svea
        $sveaOrderId = $this->getCheckoutSession()->getSveaOrderId(); //check session for Svea Payment Id
        if($sveaOrderId) {
            try {

                // here we should check if we need to update the svea order!
                if ($sveaHandler->checkIfPaymentShouldBeUpdated($newSignature, $this->getCheckoutSession()->getSveaQuoteSignature())) {
                    // try to update svea order data
                    $sveaHandler->updateCheckoutPaymentByQuoteAndOrderId($quote, $sveaOrderId);

                    // Update new svea quote signature!
                    $this->getCheckoutSession()->setSveaQuoteSignature($newSignature);
                } else {

                    // if we should update the order, we also set the svea iframe here
                    $sveaOrder = $sveaHandler->loadSveaOrderById($sveaOrderId, true);

                    // do some validations!
                    // if something went wrong and the order was placed by customer, but not saved in magento!
                    // if the svea order status is final, and the client order number matches with the current quote
                    // we should cancel this svea order and throw an exception,
                    if ($sveaOrder->getStatus() === 'Final' && $sveaOrder->getClientOrderNumber() == $this->getSveaPaymentHandler()->generateReferenceByQuoteId($quote->getId())) {

                        // TODO cancel svea Order
                        /*
                        try {
                            $this->sveaOrderManagement->cancelOrder($sveaOrder->getOrderId());
                        } catch (\Exception $e) {
                            // do nothing!
                        }
                        */
                        throw new \Exception("This order is already placed in Svea. We cancel it and create an new.");
                    }
                }

            } catch(\Exception $e) {

                // If we couldn't update the svea order flow for any reason, we try to create an new one...

                // remove sessions
                $this->getCheckoutSession()->unsSveaOrderId(); //remove payment id from session
                $this->getCheckoutSession()->unsSveaQuoteSignature(); //remove signature from session



                // TODO this doesnt seems to create an new order!!!

                // this will create an api call to svea and initiaze an new payment
                $newPaymentId = $sveaHandler->initNewSveaCheckoutPaymentByQuote($quote);

                //save the payment id and quote signature in checkout/session
                $this->getCheckoutSession()->setSveaOrderId($newPaymentId);
                $this->getCheckoutSession()->setSveaQuoteSignature($newSignature);

                // We log this!
                $this->getLogger()->error("Trying to create a new payment because we could not Update Svea Checkout Payment for ID: {$sveaOrderId}, Error: {$e->getMessage()} (see exception.log)");
                $this->getLogger()->error($e);
            }

        } else {

            // this will create an api call to svea and initiaze a new payment
            $sveaOrderId = $sveaHandler->initNewSveaCheckoutPaymentByQuote($quote);

            //save svea uri in checkout/session
            $this->getCheckoutSession()->setSveaOrderId($sveaOrderId);
            $this->getCheckoutSession()->setSveaQuoteSignature($newSignature);
        }


        return $this;
    }

    /**
     * This vill be used in ajax calls
     * @throws LocalizedException
     */
    public function updateSveaPayment($sveaOrderId)
    {
        $quote       = $this->getQuote();
        $sveaHandler = $this->getSveaPaymentHandler()->assignQuote($quote); // this will also validate the quote!

        // a signature is a md5 hashed value of the customer quote. Using this we can store the hash in session and compare the values
        $newSignature = $this->getHelper()->generateHashSignatureByQuote($quote);

        $sveaHandler->updateCheckoutPaymentByQuoteAndOrderId($quote, $sveaOrderId);

        // Update new svea quote signature!
        $this->getCheckoutSession()->setSveaQuoteSignature($newSignature);
    }



    //Checkout ajax updates


    /**
     * Set shipping method to quote, if needed
     *
     * @param string $methodCode
     * @return void
     */
    public function updateShippingMethod($methodCode)
    {
        $quote = $this->getQuote();
        if($quote->isVirtual()) {
            return;
        }
        $shippingAddress = $quote->getShippingAddress();
        if ($methodCode != $shippingAddress->getShippingMethod()) {
            $this->ignoreAddressValidation();
            $shippingAddress->setShippingMethod($methodCode)->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals()->save();
        }
    }


    /**
        // TODO
     * Update shipping address
     *
     * @param string $methodCode
     * @return void

    public function updateShippingAddress($data)
    {
        $quote = $this->getQuote();
        if($quote->isVirtual()) {
            return $this;
        }
        $addr = $this->getSveaPaymentHandler()->convertSveaShippingToMagentoAddress($data,$withEmpty = false);
        if(!$addr) return $this;

        $shippingAddress = $quote->getShippingAddress();


        $cnt = 0;
        foreach($addr as $field=>$value) {
            $kValue = trim(strtolower($value));
            $mValue = trim(strtolower((string)$shippingAddress->getData($field)));
            if($kValue != $mValue) {
                $shippingAddress->setData($field,$value);
                $cnt++;
            }
        }
        if($cnt) {
            $shippingAddress->setShouldIgnoreValidation(true)->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals()->save();
        }

    }
     */

    /**
     * Make sure addresses will be saved without validation errors
     *
     * @return void
     */
    private function ignoreAddressValidation()
    {
        $quote = $this->getQuote();
        $quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$quote->getIsVirtual()) {
            $quote->getShippingAddress()->setShouldIgnoreValidation(true);
        }
    }


    /**
     * @param $sveaOrderId
     * @return true|void
     * @throws CheckoutException
     */
    public function tryToSaveSveaPayment($sveaOrderId)
    {
        $session = $this->getCheckoutSession();

        $checkoutPaymentId = $session->getSveaOrderId();
        $quote = $this->getQuote();

        if (!$quote) {
            return $this->throwRedirectToCartException(__("Your session has expired. Quote missing."));
        }

        if (!$sveaOrderId || !$checkoutPaymentId || ($sveaOrderId != $checkoutPaymentId)) {
            $this->getLogger()->error("Invalid request");
            if (!$checkoutPaymentId) {
                $this->getLogger()->error("Save Order: No svea checkout payment id in session.");
                return $this->throwRedirectToCartException(__("Your session has expired."));
            }

            if ($sveaOrderId != $checkoutPaymentId) {
                return $this->getLogger()->error("Save Order: The session has expired or is wrong.");
            }

            return $this->getLogger()->error("Save Order: Invalid data.");
        }


        try {
            $payment = $this->getSveaPaymentHandler()->loadSveaOrderById($sveaOrderId);
        } catch (ClientException $e) {
            if ($e->getHttpStatusCode() == 404) {
                $this->getLogger()->error("Save Order: The svea order with ID: " . $sveaOrderId . " was not found in svea.");
                return $this->throwReloadException(__("Could not create an order. The payment was not found in svea."));
            } else {
                $this->getLogger()->error("Save Order: Something went wrong when we tried to fetch the order ID from Svea. Http Status code: " . $e->getHttpStatusCode());
                $this->getLogger()->error("Error message:" . $e->getMessage());
                $this->getLogger()->debug($e->getResponseBody());

                return $this->throwReloadException(__("Could not create an order, please contact site admin. Svea seems to be down!"));
            }
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong.')
            );

            $this->getLogger()->error("Save Order: Something went wrong. Might have been the request parser. Order ID: ". $checkoutPaymentId. "... Error message:" . $e->getMessage());
            return $this->throwReloadException(__("Something went wrong... Contact site admin."));
        }


        if ($payment->getClientOrderNumber() !== $this->getSveaPaymentHandler()->generateReferenceByQuoteId($quote->getId())) {
            $this->getLogger()->error("Save Order: The customer Quote ID doesn't match with the svea order reference: " . $payment->getClientOrderNumber());
            return $this->throwReloadException(__("Could not create an order. Invalid data. Contact admin."));
        }

        if ($payment->getStatus() !== "Created") { // TODO  maybe Final
            $this->getLogger()->error("Save Order: Order doesnt have correct status for the order id: " . $payment->getOrderId() . "... This must mean that they customer hasn't checked out yet!");
            return $this->throwReloadException(__("We could not create your order... The order hasn't reached Svea. Order id: %1", $payment->getOrderId()));
        }


        try {
            $order = $this->placeOrder($payment, $quote);
        } catch (\Exception $e) {
            $this->getLogger()->error("Could not place order for svea order with order id: " . $payment->getOrderId() . ", Quote ID:" . $quote->getId());
            $this->getLogger()->error("Error message:" . $e->getMessage());

            return $this->throwReloadException(__("We could not create your order. Please contact the site admin with this error and order id: %1",$payment->getOrderId()));
        }


        // TODO!
        /*
        try {
            $this->updateMagentoPaymentReference($order, $sveaOrderId);
        } catch (\Exception $e) {
            $this->getLogger()->error("
                Order created with ID: " . $order->getIncrementId(). ". 
                But we could not update reference ID at svea. Please handle it manually, it has id: quote_id_: ".$quote->getId()."...  Svea Order ID: " . $payment->getOrderId()
            );

            // lets ignore this and save it in logs! let customer see his/her order confirmation!
            $this->getLogger()->error("Error message:" . $e->getMessage());
        }
        */

        // clear old sessions
        $session->clearHelperData();
        $session->clearQuote()->clearStorage();


        // we set new sessions
        $session
            ->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());


        return true;
    }

    /**
     * @param GetOrderResponse $sveaOrder
     * @param Quote $quote
     * @return mixed
     * @throws \Exception
     */
    protected function placeOrder(GetOrderResponse $sveaOrder, Quote $quote) {

        //prevent observer to mark quote dirty, we will check here if quote was changed and, if yes, will redirect to checkout
        $this->setDoNotMarkCartDirty(true);

        /* // TODO
        try {
            $this->validateSveaPayment($sveaOrder,$quote);
        } catch (\Exception $e) {
            throw $e;
        }
        */


        //this will be saved in order
        $quote->setSveaOrderId($sveaOrder->getOrderId());


        // TODO get billing address as well!
        $shipping = $this->getSveaPaymentHandler()->convertSveaShippingToMagentoAddress($sveaOrder);


        // WE only get shipping address from svea!
        $billingAddress = $quote->getBillingAddress();
        $billingAddress->addData($shipping)
            ->setCustomerAddressId(0)
            ->setSaveInAddressBook(0)
            ->setShouldIgnoreValidation(true);

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($shipping)
            ->setSameAsBilling(1)
            ->setCustomerAddressId(0)
            ->setSaveInAddressBook(0)
            ->setShouldIgnoreValidation(true);


        $quote->setCustomerEmail($billingAddress->getEmail());

        $customer      = $quote->getCustomer(); //this (customer_id) is set into self::init
        $createCustomer = false;


        if ($customer && $customer->getId()) {
            $quote->setCheckoutMethod(self::METHOD_CUSTOMER)
                ->setCustomerId($customer->getId())
                ->setCustomerEmail($customer->getEmail())
                ->setCustomerFirstname($customer->getFirstname())
                ->setCustomerLastname($customer->getLastname())
                ->setCustomerIsGuest(false);
        } else {
            //checkout method
            $quote->setCheckoutMethod(self::METHOD_GUEST)
                ->setCustomerId(null)
                ->setCustomerEmail($billingAddress->getEmail())
                ->setCustomerFirstname($billingAddress->getFirstname())
                ->setCustomerLastname($billingAddress->getLastname())
                ->setCustomerIsGuest(true)
                ->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);

            // register the customer, if its required, the customer will then be registered after order is placed
            if ($billingAddress->getEmail() && $this->getHelper()->registerCustomerOnCheckout()) {
                if (!$this->_customerEmailExists($billingAddress->getEmail(),$quote->getStore()->getWebsiteId())) {
                    $createCustomer = true;
                }
            }
        }


        //set payment
        $payment = $quote->getPayment();

        //force payment method
        if(!$payment->getMethod() || $payment->getMethod() != $this->_paymentMethod) {
            $payment->unsMethodInstance()->setMethod($this->_paymentMethod);
        }

        $paymentData = (new DataObject())
            ->setSveaOrderId($sveaOrder->getOrderId())
            ->setCountryId($shippingAddress->getCountryId());


        $quote->getPayment()->getMethodInstance()->assignData($paymentData);
        $quote->setSveaOrderId($sveaOrder->getOrderId()); //this is used by pushAction


        /*
        // we need to add invoice fee here to order if its enabled
        if ($this->getHelper()->useInvoiceFee()
            && $sveaOrder->getPaymentDetails()->getPaymentType() === "INVOICE"
            && $sveaOrder->getPaymentDetails()->getPaymentMethod() === "EasyInvoice"
        ) {

            $invoiceFee = $this->getHelper()->getInvoiceFee() * 1.25; // TODO remove hardcode!

            $quote->setSveaInvoiceFee($invoiceFee);
            $quote->setGrandTotal($quote->getGrandTotal() + $invoiceFee);
            $quote->setBaseGrandTotal($quote->getGrandTotal() + $invoiceFee);

            $quote->collectTotals();
        }
        */


        //- do not recollect totals
        $quote->setTotalsCollectedFlag(true);


        //!
        // Now we create the order from the quote
        $order = $this->quoteManagement->submit($quote);



        $this->_eventManager->dispatch(
            'checkout_type_onepage_save_order_after',
            ['order' => $order, 'quote' => $this->getQuote()]
        );

        if ($order->getCanSendNewEmailFlag()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->_logger->critical($e);
            }
        }


        // add order information to the session
        $this->_checkoutSession
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());



        $this->_eventManager->dispatch(
            'checkout_submit_all_after',
            [
                'order' => $order,
                'quote' => $this->getQuote()
            ]
        );

        if ($createCustomer) {
            //@see Magento\Checkout\Controller\Account\Create
            try {
                $this->context->getOrderCustomerManagement()->create($order->getId());
            } catch (\Exception $e) {
                $this->_logger->error(__("Order %1, cannot create customer [%2]: %3",$order->getIncrementId(),$order->getCustomerEmail(),$e->getMessage()));
                $this->_logger->critical($e);
            }
        }


        if($order->getCustomerEmail() && $this->getHelper()->subscribeNewsletter($this->getQuote())) {
            try {
                //subscribe to newsletter
                $this->orderSubscribeToNewsLetter($order);
            } catch(\Exception $e) {
                $this->_logger->error("Cannot subscribe customer ({$order->getCustomerEmail()}) to the Newsletter: ".$e->getMessage());
            }
        }

        return $order;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param $sveaOrderId
     * @return void
     * @throws ClientException
     */
    public function updateMagentoPaymentReference(\Magento\Sales\Model\Order $order, $sveaOrderId)
    {
        $this->getSveaPaymentHandler()->updateMagentoPaymentReference($order, $sveaOrderId);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     * @throws \Exception
     */
    protected function orderSubscribeToNewsLetter(\Magento\Sales\Model\Order $order)
    {
        $email = $order->getCustomerEmail();
        if (!$email) {
            return false;
        }

        $subscriber = $this->context->getSubscriber();
        $subscriber->loadByEmail($email);

        if($subscriber->getId()) {
            return false;
        }

        return $subscriber->subscribe($email);
    }




    /**
     * @param $message
     * @throws CheckoutException
     */
    protected function throwRedirectToCartException($message)
    {
        throw new CheckoutException($message,'checkout/cart');
    }

    /**
     * @param $message
     * @throws CheckoutException
     */
    protected function throwReloadException($message)
    {
        throw new CheckoutException($message,'*/*');
    }


    /**
     * Get frontend checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     * @codeCoverageIgnore
     */
    public function getCheckoutSession()
    {
        return $this->_checkoutSession; //@see Onepage::__construct
    }

    /** @return \Svea\Checkout\Model\Svea\Order */
    public function getSveaPaymentHandler()
    {
        return $this->context->getSveaOrderHandler();
    }


    /**
     * @param $markDirty
     */
    public function setDoNotMarkCartDirty($markDirty)
    {
        $this->_doNotMarkCartDirty = (bool) $markDirty;
    }

    /**
     * @return bool
     */
    public function getDoNotMarkCartDirty()
    {
        return $this->_doNotMarkCartDirty;
    }
}