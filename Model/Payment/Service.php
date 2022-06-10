<?php
/**
 * @copyright 2017 Sapient
 */
namespace Sapient\Worldpay\Model\Payment;

class Service
{

    /** @var \Sapient\Worldpay\Model\Request\PaymentServiceRequest */
    protected $_paymentServiceRequest;
    /** @var \Sapient\Worldpay\Model\Request\AdminhtmlResponse */
    protected $_adminhtmlResponse;
    /** @var \Sapient\Worldpay\Model\Payment\Update\Factory */
    protected $_paymentUpdateFactory;
    /** @var \Sapient\Worldpay\Model\Request\PaymentServiceRequest */
    protected $_redirectResponse;
    /** @var \Sapient\Worldpay\Model\Request\PaymentModel */
    protected $_paymentModel;
    /** @var \Sapient\Worldpay\Model\Request\Helper */
    protected $_helper;
    /**
     * Constructor
     *
     * @param \Sapient\Worldpay\Model\Payment\Update\Factory $paymentupdatefactory
     * @param \Sapient\Worldpay\Model\Request\PaymentServiceRequest $paymentservicerequest
     * @param \Sapient\Worldpay\Model\Worldpayment $worldpayPayment
     */
    public function __construct(
        \Sapient\Worldpay\Model\Payment\Update\Factory $paymentupdatefactory,
        \Sapient\Worldpay\Model\Request\PaymentServiceRequest $paymentservicerequest,
        \Sapient\Worldpay\Model\Worldpayment $worldpayPayment
    ) {
        $this->paymentupdatefactory = $paymentupdatefactory;
        $this->paymentservicerequest = $paymentservicerequest;
        $this->worldpayPayment = $worldpayPayment;
    }

    /**
     * CreatePaymentUpdateFromWorldPayXml
     *
     * @param string|int|xml $xml
     */
    public function createPaymentUpdateFromWorldPayXml($xml)
    {
        return $this->_getPaymentUpdateFactory()
            ->create(new \Sapient\Worldpay\Model\Payment\StateXml($xml));
    }

    /**
     * GetPaymentUpdateFactory
     */
    protected function _getPaymentUpdateFactory()
    {
        if ($this->_paymentUpdateFactory === null) {
            $this->_paymentUpdateFactory = $this->paymentupdatefactory;
        }

        return $this->_paymentUpdateFactory;
    }

    /**
     * GetPaymentUpdateFactory
     *
     * @param \Sapient\Worldpay\Model\Payment\StateInterface $state
     */
    public function createPaymentUpdateFromWorldPayResponse(\Sapient\Worldpay\Model\Payment\StateInterface $state)
    {
        return $this->_getPaymentUpdateFactory()
            ->create($state);
    }
    
    /**
     * GetPaymentUpdateXmlForNotification
     *
     * @param string|xml $xml
     */
    public function getPaymentUpdateXmlForNotification($xml)
    {
        $paymentNotifyService = new \SimpleXmlElement($xml);
        $lastEvent = $paymentNotifyService->xpath('//lastEvent');
        $journal = $paymentNotifyService->xpath('//journal/journalReference');
        if (!empty($journal) && $lastEvent[0] == 'CAPTURED') {
            $partialCaptureReference = (array) $journal[0]->attributes()['reference'][0];
            $ordercodenode = $paymentNotifyService->xpath('//orderStatusEvent');
            $ordercode = (array) $ordercodenode[0]->attributes()['orderCode'][0];
            $nodes = $paymentNotifyService->xpath('//payment/balance');
            $getNodeValue = '';
            $getAttibute = '';
            if (isset($nodes, $lastEvent[0], $partialCaptureReference[0])) {
                if ($nodes && $lastEvent[0] == 'CAPTURED' && $partialCaptureReference[0] == 'Partial Capture') {
                    $getAttibute = (array) $nodes[0]->attributes()['accountType'];
                    $getNodeValue = $getAttibute[0];
                }
                if ($lastEvent[0] == 'CAPTURED' && $partialCaptureReference[0] == 'Partial Capture'
                        && $getNodeValue == 'IN_PROCESS_AUTHORISED') {
                    $worldpaypayment = $this->worldpayPayment->loadByWorldpayOrderId($ordercode[0]);
                    if (isset($worldpaypayment)) {
                        $worldpaypayment->setData('payment_status', $lastEvent[0]);
                        $worldpaypayment->save();
                    }
                    $gatewayError = 'Notification received for Partial Captutre';
                    throw new \Magento\Framework\Exception\CouldNotDeleteException(__($gatewayError));
                }
            }
        }
    }

    /**
     * Payment update xml for order
     *
     * @param \Sapient\Worldpay\Model\Order $order
     * @return SimpleXMLElement
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function getPaymentUpdateXmlForOrder(\Sapient\Worldpay\Model\Order $order)
    {
        $worldPayPayment = $order->getWorldPayPayment();

        if (!$worldPayPayment) {
            return false;
        }
        $rawXml = $this->paymentservicerequest->inquiry(
            $worldPayPayment->getMerchantId(),
            $worldPayPayment->getWorldpayOrderId(),
            $worldPayPayment->getStoreId(),
            $order->getPaymentMethodCode(),
            $worldPayPayment->getPaymentType()
        );
        
        $paymentService = new \SimpleXmlElement($rawXml);
        $lastEvent = $paymentService->xpath('//lastEvent');
        $partialCaptureReference = $paymentService->xpath('//reference');
        $ordercodenode = $paymentService->xpath('//orderStatus');
        $ordercode = (array)$ordercodenode[0]->attributes()['orderCode'][0];
    
        $nodes = $paymentService->xpath('//payment/balance');
        $getNodeValue ='';
        $getAttibute = '';
        
        if (isset($nodes, $lastEvent[0], $partialCaptureReference[0])) {
            if ($nodes && $lastEvent[0] == 'CAPTURED' && $partialCaptureReference[0] == 'Partial Capture') {
                $getAttibute = (array) $nodes[0]->attributes()['accountType'];
                $getNodeValue = $getAttibute[0];
            }
            if ($lastEvent[0] == 'CAPTURED' && $partialCaptureReference[0] == 'Partial Capture'
            && $getNodeValue == 'IN_PROCESS_AUTHORISED') {
                $worldpaypayment= $this->worldpayPayment->loadByWorldpayOrderId($ordercode[0]);
                if (isset($worldpaypayment)) {
                    $worldpaypayment->setData('payment_status', $lastEvent[0]);
                    $worldpaypayment->save();
                }
                $gatewayError = 'Sync status action not possible for this Partial Captutre Order.';
                throw new \Magento\Framework\Exception\CouldNotDeleteException(__($gatewayError));
            }
        }
        return simplexml_load_string($rawXml);
    }

    /**
     * Set global payment data
     *
     * @param object $paymentUpdate
     */
    public function setGlobalPaymentByPaymentUpdate($paymentUpdate)
    {
        $this->worldpayPayment->loadByWorldpayOrderId($paymentUpdate->getTargetOrderCode());
    }
}
