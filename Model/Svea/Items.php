<?php


namespace Svea\Checkout\Model\Svea;

use Svea\Checkout\Model\CheckoutException;
use Svea\Checkout\Model\Client\DTO\Order\OrderRow;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Svea (Checkout) Order Items Model
 */

class Items
{


    /**
     * @var \Svea\Checkout\Helper\Data
     */
    protected $_helper;

    /** @var \Magento\Tax\Model\Calculation */
    protected $calculationTool;


    /**
     * Catalog product configuration
     *
     * @var \Magento\Catalog\Helper\Product\Configuration
     */
    protected $_productConfig;

    protected $_cart     = array();
    protected $_discounts = array();
    protected $_maxvat = 0;
    protected $_inclTAX = false;
    protected $_toInvoice = false;
    protected $_store = null;
    protected $_itemsArray = [];


    public function __construct(
        \Svea\Checkout\Helper\Data $helper,
        \Magento\Catalog\Helper\Product\Configuration $productConfig,
        \Magento\Tax\Model\Calculation $calculationTool
    ) {
        $this->_helper = $helper;
        $this->_productConfig = $productConfig;
        $this->calculationTool = $calculationTool;

        // resets all values
        $this->init();
    }


    public function init($store = null)
    {
        $this->_store = $store;
        $this->_cart = array();
        $this->_discounts = array();
        $this->_maxvat = 0;
        $this->_inclTAX = false;
        $this->_toInvoice = false;

        return $this;
    }

    /**
     * @param $items mixed
     * @return $this
     */
    public function addItems($items)
    {
        $isQuote = null;
        foreach($items as $magentoItem) {

            if(is_null($isQuote)) {
                $isQuote = ($magentoItem instanceof \Magento\Quote\Model\Quote\Item);
            }

            //invoice or creditmemo item
            $oid = $magentoItem->getData('order_item_id');
            if($oid) {
                $mainItem = $magentoItem->getOrderItem();
            } else {
                //quote or order item
                $mainItem = $magentoItem;
            }

            // ignore these
            if($mainItem->getParentItemId() || $mainItem->isDeleted())  {
                continue;
            }

            if($magentoItem instanceof \Magento\Sales\Model\Order\Item ) {
                $qty = $magentoItem->getQtyOrdered();
                if ($this->_toInvoice) {
                    $qty -= $magentoItem->getQtyInvoiced();
                }

                $magentoItem->setQty($qty);
            }


            $allItems             = array();
            $bundle               = false;
            $isChildrenCalculated = false;
            $parentQty            = 1;
            $parentComment = null;

            //for bundle product, want to add also the bundle, with price 0 if is children calculated
            if($mainItem->getProductType() == 'bundle' || ($mainItem->getHasChildren() && $mainItem->isChildrenCalculated())) {

                $bundle               = true;
                $isChildrenCalculated = $mainItem->isChildrenCalculated();
                if($isChildrenCalculated) {

                    if($isQuote) {
                        // this is only required in the Quote object (children qty is not parent * children)
                        // its already multiplied in the Order Object
                        $parentQty = $magentoItem->getQty();
                    }
                } else {
                    $allItems[] = $magentoItem; //add bundle product
                    $parentComment         = "Bundle Product";
                }

                $children = $this->getChildrenItems($magentoItem);
                if($children) {
                    foreach($children as $child) {
                        if($child->isDeleted())  {
                            continue;
                        }

                        $allItems[] = $child;
                    }
                }
            } else {
                //simple product
                $allItems[] = $magentoItem;
            }


            // Now we can loop through the items!
            foreach($allItems as $item) {

                $oid = $item->getData('order_item_id');
                if($oid) { //invoice or creditmemo item
                    $mainItem = $item->getOrderItem();
                } else { //quote or order item
                    $mainItem = $item;
                }

                if($item instanceof \Magento\Sales\Model\Order\Item ) {
                    $qty = $item->getQtyOrdered();
                    if($this->_toInvoice) {
                        $qty -= $item->getQtyInvoiced();
                    }

                    if($qty == 0) {
                        continue;
                    }

                    // we set the amount of quantity
                    $item->setQty($qty);
                }

                $addPrices = true;
                if($bundle) {
                    if(!$mainItem->getParentItemId()) { //main product, add prices if not children calculated
                        $comment = $parentComment;
                        $addPrices = !$isChildrenCalculated;
                    } else { //children, add price only if children calculated
                        $comment = '';
                        $addPrices = $isChildrenCalculated;
                    }
                } else {

                    $comment = array();
                    //add configurable/children information, as comment
                    if($isQuote) {
                        $options = $this->_productConfig->getOptions($item);
                    } else {
                        $options = null;
                    }

                    if ($options) {
                        foreach($options as $option) {
                            if(isset($option['label']) && isset($option['value'])) {
                                $comment[] = $option['label'] . ' : ' . $option['value'];
                            }
                        }
                    }

                    $comment = implode('; ',$comment);
                }


                $vat = $mainItem->getTaxPercent();
                if ($addPrices && ($item->getTaxAmount() != 0) && ($vat == 0)) {
                    // if vat is not set, we try to calculate it manually
                    //calculate vat if not set
                    $tax = $item->getPriceInclTax() - $item->getPrice();
                    if($item->getPrice() != 0 && $tax != 0) {
                        $vat = $tax / $item->getPrice() * 100;
                    }
                }

                // fix the vat
                $vat = round($vat,0);

                // We save the maximum vat rate used. We will use the maximum vat rate on invoice fee and shipping fee.
                if ($vat > $this->_maxvat) {
                    $this->_maxvat = $vat;
                }


                //$items with parent id are children of a bundle product;
                //if !$withPrice, add just bundle product (!$getParentId) with price,
                //the child will be without price (price = 0)

                $qty = $item->getQty();
                if($isQuote && $item->getParentItemId()) {
                    $qty = $qty*$parentQty; //parentQty will be != 1 only for quote, when item qty need to be multiplied with parent qty (for bundle)
                }

                $sku  = $item->getSku();
                //make sku unique (sku could not be unique when we have product with options)
                if(isset($this->_cart[$sku])) {
                    $sku = $sku.'-'.$item->getId();
                }

                $unitPriceInclTaxes = $addPrices ? $this->addZeroes($item->getPriceInclTax()) : 0;
                $unitPriceExclTax = $addPrices ? $this->addZeroes($item->getPrice()) : 0;

                //
                $orderItem = new OrderRow();
                $orderItem
                    ->setArticleNumber($sku)
                    ->setName($item->getName()." ".($comment?"({$comment})":""))
                    ->setUnit("st") // TODO! We need to map these somehow!
                    ->setQuantity($this->addZeroes(round($qty,0)))
                    ->setVatPercent($this->addZeroes($vat)) // the tax rate i.e 25% (2500)
                    ->setUnitPrice($unitPriceInclTaxes); // incl. tax price per item

                // add to array
                $this->_cart[$sku] = $orderItem;



                if($addPrices) {

                    //keep discounts grouped by VAT
                    //if catalog prices include tax, then discount INCLUDE TAX (tax coresponding to that discount is set onto discount_tax_compensation_amount)
                    //if catalog prices exclude tax, alto the discount excl. tax

                    $discountAmount = $item->getDiscountAmount();
                    if($this->_toInvoice) {
                        $discountAmount -= $item->getDiscountInvoiced(); //remaining discount
                    }

                    if($discountAmount != 0) {

                        //check if Taxes are applied BEFORE or AFTER the discount
                        //if taxes are applied BEFORE the discount we have row_total_incl_tax = row_total+tax_amount

                        if($vat != 0 && abs($item->getRowTotalInclTax() - ($item->getRowTotal()+$item->getTaxAmount())) < .001) {
                            //add discount without VAT (is not OK for EU, but, it is customer setting/choice
                            $vat =0;
                        }

                        if(!isset($this->_discounts[$vat])) {
                            $this->_discounts[$vat] = 0;
                        }

                        if($vat != 0 && $item->getDiscountTaxCompensationAmount() == 0) { //discount without taxes, we want discount INCL taxes
                            $discountAmount += $discountAmount*$vat/100;
                        }

                        $this->_discounts[$vat] +=  $discountAmount; //keep products discount, per tax percent
                    }
                }
            }
        }

        return $this;
    }



    public function addShipping($address) {

        if($this->_toInvoice && $address->getBaseShippingAmount() <= $address->getBaseShippingInvoiced()) {
            return $this;
        }

        $taxAmount = $address->getShippingTaxAmount() + $address->getShippingHiddenTaxAmount();
        $exclTax    = $address->getShippingAmount();
        $inclTax    = $address->getShippingInclTax();
        $tax        = $inclTax-$exclTax;

        //
        if($exclTax != 0 && $tax > 0) {
            $vat = $tax /  $exclTax  * 100;
        } else {
            $vat = 0;
        }

        $vat = round($vat,0);
        if($vat>$this->_maxvat) {
            $this->_maxvat = $vat;
        }


        //
        $orderItem = new OrderRow();
        $orderItem
            ->setArticleNumber('shipping_fee')
            ->setName((string)__('Shipping Fee (%1)',$address->getShippingDescription()))
            ->setUnit("st") // TODO! We need to map these somehow!
            ->setQuantity($this->addZeroes(1))
            ->setVatPercent($this->addZeroes($vat)) // the tax rate i.e 25% (2500)
            ->setUnitPrice($this->addZeroes($inclTax)); // incl. tax price per item


        // add to array!
        $this->_cart['shipping_fee'] = $orderItem;

        //keep discounts grouped by VAT

        //if catalog prices include tax, then discount INCLUDE TAX (tax coresponding to that discount is set onto shipping_discount_tax_compensation_amount)
        //if catalog prices exclude tax, alto the discount excl. tax

        $discountAmount = $address->getShippingDiscountAmount();

        if($discountAmount != 0) {

            //check if Taxes are applied BEFORE or AFTER the discount
            //if taxes are applied BEFORE the discount we have shipping_incl_tax = shipping_amount + shipping_tax_amount
            if($vat != 0 && abs($address->getShippingInclTax() - ($address->getShippingAmount()+$address->getShippingTaxAmount())) < .001) {
                //the taxes are applied BEFORE discount; add discount without VAT (is not OK for EU, but, is customer settings
                $vat =0;
            }

            if(!isset($this->_discounts[$vat])) {
                $this->_discounts[$vat] = 0;
            }

            if($vat != 0 && $address->getShippingDiscountTaxCompensationAmount() == 0) {   //prices (and discount) EXCL taxes,
                $discountAmount += $discountAmount*$vat/100;
            }

            // set for later
            $this->_discounts[$vat] += $discountAmount;
        }
        return $this;
    }

    /**
     * @param $invoiceLabel
     * @param $invoiceFee
     * @param $vatIncluded
     */
    public function addInvoiceFeeItem($invoiceLabel, $invoiceFee, $vatIncluded)
    {
        $item = $this->generateInvoiceFeeItem($invoiceLabel,$invoiceFee, $vatIncluded);
        $this->_cart[$item->getArticleNumber()] = $item;
    }

    /**
     * @param $invoiceLabel
     * @param $invoiceFee
     * @param $vatIncluded
     * @return OrderRow
     */
    public function generateInvoiceFeeItem($invoiceLabel, $invoiceFee, $vatIncluded)
    {
        $feeItem = new OrderRow();
        $taxRate = $this->getMaxVat();

        // basic values if taxes is 0
        $invoiceFeeExclTax = $invoiceFee;
        $invoiceFeeInclTax = $invoiceFee;

        // here we calculate if there are taxes!
        if ($taxRate > 0) {

            if (!$vatIncluded) {
                // i.e: 20 * ((100 + 25) / 100) =
                // 20 * 125/100 =
                // 20 * 1.25 = 25
                $invoiceFeeInclTax = $invoiceFee * ((100 + $taxRate) / 100);

            } else {
                // with taxes!
                $invoiceFeeInclTax = $invoiceFee;
            }
        }

        $feeItem
            ->setName($invoiceLabel)
            ->setArticleNumber(strtolower(str_replace(" ", "_", $invoiceLabel)))
            ->setVatPercent($this->addZeroes($taxRate))
            ->setUnit("st")
            ->setQuantity($this->addZeroes(1))
            ->setUnitPrice($this->addZeroes($invoiceFeeInclTax)); // // incl. tax

        return $feeItem;
    }

    // TODO!!
    public function addDiscounts($couponCode)
    {

        foreach($this->_discounts as $vat=> $amountInclTax) {
            if ($amountInclTax==0)  {
                continue;
            }

            $reference  = 'discount'.(int)$vat;
            if ($this->_toInvoice) {
                $reference = 'discount-toinvoice';
            }


            // TODO!


            $taxAmount = $this->getTotalTaxAmount($amountInclTax, $vat);
            $amountInclTax = $this->addZeroes($amountInclTax);
            $amountExclTax = $amountInclTax - $taxAmount;

            $orderItem = new OrderRow();
            $orderItem
                ->setArticleNumber($reference)
                ->setName($couponCode?(string)__('Discount (%1)',$couponCode):(string)__('Discount'))
                ->setUnit("st")
                ->setQuantity($this->addZeroes(1))
                ->setVatPercent($this->addZeroes($vat)) // the tax rate i.e 25% (2500)
                ->setUnitPrice(0); // incl. tax price per item


            $this->_cart[$reference] = $orderItem;
        }

        return $this;

    }

    public function validateTotals($grandTotal)
    {

        $calculatedTotal = 0;
        foreach($this->_cart as $item) {
            /** @var $item OrderRow */

            $total_price_including_tax = $item->getUnitPrice() * ($item->getQuantity() / 100);
            $calculatedTotal += $total_price_including_tax;
        }

        //quote/order/invoice/creditmemo total taxes
        $grandTotal = $this->addZeroes($grandTotal);
        $difference    = $grandTotal-$calculatedTotal;

        //no correction required
        if($difference == 0) {
            return $this;
        }

        throw new CheckoutException(__("The grand total price does not match the price being sent to Svea. Please contact an admin or use another checkout method."), 'checkout/cart');
    }


    /**
     * Getting all available children for Invoice, Shipment or CreditMemo item
     *
     * @param \Magento\Framework\DataObject $item
     * @return array
     */
    public function getChildrenItems($item)
    {
        $items = null;
        if ($item instanceof \Magento\Sales\Model\Order\Invoice\Item) {
            $parentId = 'INV'.$item->getInvoice()->getId();
            if(!isset($this->_itemsArray[$parentId])) {
                $this->_itemsArray[$parentId] = array();
                $items = $item->getInvoice()->getAllItems();
            }
        } elseif ($item instanceof \Magento\Sales\Model\Order\Shipment\Item) {
            $parentId = 'SHIP'.$item->getShipment()->getId();
            if(!isset($this->_itemsArray[$parentId])) {
                $this->_itemsArray[$parentId] = array();
                $items = $item->getShipment()->getAllItems();
            }
        } elseif ($item instanceof \Magento\Sales\Model\Order\Creditmemo\Item) {
            $parentId = 'CRDM'.$item->getCreditmemo()->getId();
            if(!isset($this->_itemsArray[$parentId])) {
                $this->_itemsArray[$parentId] = array();
                $items = $item->getCreditmemo()->getAllItems();
            }
        } elseif ($item instanceof \Magento\Sales\Model\Order\Item)  {
            return $item->getChildrenItems();
        } else { //quote
            return  $item->getChildren();
        }



        if ($items) {
            foreach ($items as $value) {
                $parentItem = $value->getOrderItem()->getParentItem();

                //we want only children (parent is already added), this is why this is commented
                if ($parentItem) {
                    $this->_itemsArray[$parentId][$parentItem->getId()][$value->getOrderItemId()] = $value;
                }
            }
        }

        if (isset($this->_itemsArray[$parentId][$item->getOrderItem()->getId()])) {
            return $this->_itemsArray[$parentId][$item->getOrderItem()->getId()];
        } else {
            return array();
        }
    }


    /**
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    public function generateOrderItemsFromQuote(Quote $quote)
    {
        $this->init($quote->getStore());

        $billingAddress = $quote->getBillingAddress();
        if($quote->isVirtual()) {
            $shippingAddress = $billingAddress;
        } else {
            $shippingAddress = $quote->getShippingAddress();
        }

        /*Get all cart items*/
        $cartItems = $quote->getAllVisibleItems(); //getItemParentId is null and !isDeleted

        $this->addItems($cartItems);
        if(!$quote->isVirtual()) {
            $this->addShipping($shippingAddress);
        }

        $this->addDiscounts($quote->getCouponCode());


        try {
            $this->validateTotals($quote->getGrandTotal());
        } catch (\Exception $e) {
            //!! todo handle somehow!
            throw $e;
        }



        return array_values($this->_cart);
    }


    //generate Svea items from Magento Order
    public function fromOrder(Order $order) {
        $this->init($order->getStore());


        // we will validate the grand total that we send to svea, since we dont send invocie fee with it, we remove it now
        $grandTotal = $order->getGrandTotal();
        $this->addItems($order->getAllItems())
            ->addShipping($order)
            ->addDiscounts($order->getCouponCode())
            ->validateTotals($grandTotal);

        return $this->_cart;
    }


    /**
     * @param Order\Invoice $invoice
     * @return void
     */
    public function addSveaItemsByInvoice(Order\Invoice $invoice) {
        $order  = $invoice->getOrder();

        $this
            ->init($order->getStore())
            ->addItems($invoice->getAllItems());


        if($invoice->getShippingAmount() != 0 && $order->getShippingDiscountAmount() !=0 && $invoice->getShippingDiscountAmount() == 0) {
            //copy discount shipping discount amount from order (because is not copied to the invoice)
            $oShippingDiscount = $order->getShippingDiscountAmount();
            $iShipping = $invoice->getShippingAmount();
            $oShipping = $order->getShippingAmount();

            //this should never happen but if it does , we will adjust shipping discount amoutn
            if($iShipping != $oShipping && $oShipping>0) {
                $oShippingDiscount = round($iShipping*$oShippingDiscount/$oShipping,4);
            }

            $invoice->setShippingDiscountAmount($oShippingDiscount);
        }

        if($invoice->getShippingAmount() != 0) {
            $this->addShipping($invoice);
        }

        //coupon code is not copied to invoice so we take it from the order!
        $this->addDiscounts($order->getCouponCode());
    }

    /**
     * This will help us generate Svea Order Items for which will be sent as a refund or partial refund.
     * We don't add discounts or shipping here even though the invoice has discounts, we only add items to be refunded.
     * Shipping amount is added IF it should be refunded as well.
     * @param Order\Creditmemo $creditMemo
     * @return void
     */
    public function addSveaItemsByCreditMemo(Order\Creditmemo $creditMemo) {
        $order = $creditMemo->getOrder();

        // no support at svea for adjustments
        // $creditMemo->getAdjustmentPositive();
        // $creditMemo->getAdjustmentNegative();

        $this->init($order->getStore());
        $this->addItems($creditMemo->getAllItems());

        if($creditMemo->getShippingAmount() != 0) {
            $this->addShipping($creditMemo);
        }

        $this->addDiscounts($order->getCouponCode()); //coupon code is not copied to invoice
    }

    public function getMaxVat()
    {
        return $this->_maxvat;
    }

    public function getCart()
    {
        return $this->_cart;
    }

    public function getTotalTaxAmount($price,$vat, $addZeroes = true)
    {
        if ($addZeroes) {
            return $this->addZeroes($this->calculationTool->calcTaxAmount($price, $vat, true));
        } else {
            return $this->calculationTool->calcTaxAmount($price, $vat, true);
        }
    }

    public function addZeroes($amount)
    {
        return round($amount * 100,0);
    }


}