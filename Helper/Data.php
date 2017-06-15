<?php
/**
 * Created by Phong Phan.
 * Copyright Mercuriel - 2016
 * Date: 01/06/2017
 * Time: 15:05
 */

namespace Mercuriel\Autoinvoice\Helper;

class Data
{
    /**
     * Parameter for Mercuriel Autoinvoice configuration
     */
    CONST ENABLE_MODULE = 'mercuriel/general/enable';
    CONST PAYMENT_INVOICE_CONF ='mercuriel/general/specificPaymentInvoice';
    CONST PAYMENT_SHIPMENT_CONF = 'mercuriel/general/specificPaymentShipments';
    CONST APPLY_ALL_METHODS ='mercuriel_default_all';
    CONST EMAIL_INVOICE = 'mercuriel/general/emailInvoice';
    CONST EMAIL_SHIPMENTS = 'mercuriel/general/emailShipment';
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;
    /**
     * @var \Magento\Sales\Model\Order\ShipmentFactory
     */
    protected $shipmentFactory;
    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $order;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;
    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;
    /**
     * @var \Magento\Sales\Model\Convert\Order
     */
    protected $convertOrder;
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $invoiceSender;
    /**
     * @var \Magento\Shipping\Model\ShipmentNotifier
     */
    protected $shipmentNotifier;
    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Sales\Api\OrderRepositoryInterface $order
     * @param \Magento\Sales\Model\Order\ShipmentFactory $shipmentFactory
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Sales\Model\Convert\Order $convertOrder
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Shipping\Model\ShipmentNotifier $shipmentNotifier
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Api\OrderRepositoryInterface $order,
        \Magento\Sales\Model\Order\ShipmentFactory $shipmentFactory,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Convert\Order $convertOrder,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Shipping\Model\ShipmentNotifier $shipmentNotifier,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->order = $order;
        $this->scopeConfig = $scopeConfig;
        $this->invoiceService = $invoiceService;
        $this->shipmentFactory = $shipmentFactory;
        $this->_transaction = $transaction;
        $this->convertOrder = $convertOrder;
        $this->_logger = $logger;
        $this->invoiceSender = $invoiceSender;
        $this->shipmentNotifier = $shipmentNotifier;
    }

    /**
     * Get Payment Method from order
     * @param $order
     * @return mixed
     */
    public function getOrderPaymentCode($order)
    {
        $payment =  $order->getPayment();
        $method = $payment->getMethodInstance();
        $orderPaymentCode = $method->getCode();
        return $orderPaymentCode;
    }

    /**
     * Create invoice after place order
     * @param $order
     * @return \Magento\Sales\Model\Order\Invoice|null|void
     */
    public function assignInvoice($order)
    {
        $invoice = NULL;
        if(!$order->canInvoice()) {
            return;
        }
        $orderPaymentCode = $this->getOrderPaymentCode($order);
        if(!$this->checkPaymentConfigByOption($orderPaymentCode,'invoice'))
        {
            return;
        }
        try{
            $invoice  = $this->invoiceService->prepareInvoice($order);
            $invoice->register();

            $invoice->getOrder()->setIsInProcess(true);
            $order->addStatusHistoryComment('Invoice Created', false);
            $transactionSave = $this->_transaction->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();
            if($this->_isEnabledInvoiceEmail())
            {
                $this->invoiceSender->send($invoice);
            }
        }catch(\Exception $e)
        {
            $order->addStatusHistoryComment('Exception message: '.$e->getMessage(), false);
            $order->save();
        }
        return $invoice;
    }

    /**
     * Create shipments
     * @param $order
     */
    public function createShipments($order)
    {
        $orderPaymentCode = $this->getOrderPaymentCode($order);
        if(!$this->checkPaymentConfigByOption($orderPaymentCode,'shipments'))
        {
            return;
        }
        if (! $order->canShip()) {
          return;
        }

        $shipment = $this->convertOrder->toShipment($order);
        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }
            $qtyShipped = $orderItem->getQtyToShip();
            $shipmentItem = $this->convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);

            $shipment->addItem($shipmentItem);
        }
        try{
            $shipment->register();
            $shipment->getOrder()->setIsInProcess(true);
            $order->addStatusHistoryComment('Shipments Created', false);
            $transactionSave = $this->_transaction->addObject($shipment)->addObject($shipment->getOrder());
            $transactionSave->save();
            $shipment->save();
            $shipment->getOrder()->save();
            if($this->_isEnabledShipmentsEmail())
            {
                $this->shipmentNotifier->notify($shipment);
            }


        }catch(\Exception $e)
        {
            $order->addStatusHistoryComment('Exception message: '.$e->getMessage(), false);
            $order->save();
        }
        return;
    }

    /**
     * Check module is enable or not
     * @return bool
     */
    public function _isEnabledModule()
    {
        return ($this->scopeConfig->getValue(self::ENABLE_MODULE)) ? true:false ;
    }

    /**
     * Get Order Data
     * @param $orderId
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    public function getOrderByOrderId($orderId)
    {
        $order = $this->order->get($orderId);
        return $order;
    }

    /**
     * Enable send email after invoice created
     * @return bool
     */
    public function _isEnabledInvoiceEmail()
    {
        return ($this->scopeConfig->getValue(self::EMAIL_INVOICE)) ? true:false ;
    }

    /**
     *  Enable send email after shipments created
     * @return bool
     */
    public function _isEnabledShipmentsEmail()
    {
        return ($this->scopeConfig->getValue(self::EMAIL_SHIPMENTS)) ? true:false ;
    }

    /**
     * Check payment method of order is enable for this module
     * @param $orderPaymentCode
     * @param $option
     * @return bool
     */
    public function checkPaymentConfigByOption($orderPaymentCode, $option)
    {
        $paymentConfig = $this->getPaymentConfigForOption($option);
        $apllyAllMethods = self::APPLY_ALL_METHODS;
        if( in_array($apllyAllMethods,$paymentConfig) || in_array($orderPaymentCode,$paymentConfig) )
        {
            return true;
        }else
        {
            return false;
        }
    }

    /**
     * Get Payment methods from configuration
     * @param $option
     * @return array
     */
    public function getPaymentConfigForOption($option)
    {
        $paymentConf = NULL;
        switch ($option)
        {
            case 'invoice':
                $paymentConf =  $this->scopeConfig->getValue(self::PAYMENT_INVOICE_CONF);
                break;
            case 'shipments':
                $paymentConf =  $this->scopeConfig->getValue(self::PAYMENT_SHIPMENT_CONF);
                break;
        }
        return  explode(',',$paymentConf);
    }
}