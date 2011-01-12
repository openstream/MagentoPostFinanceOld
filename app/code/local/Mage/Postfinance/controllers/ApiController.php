<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_Postfinance
 * @copyright   Copyright (c) 2009 Irubin Consulting Inc. DBA Varien (http://www.varien.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Postfinance Api Controller
 */
class Mage_Postfinance_ApiController extends Mage_Core_Controller_Front_Action
{
    /**
     * Order instance
     */
    protected $_order;

    /**
     * Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get singleton with Checkout by Postfinance Api
     *
     * @return Mage_Postfinance_Model_Api
     */
    protected function _getApi()
    {
        return Mage::getSingleton('postfinance/api');
    }

    /**
     * Return order instance loaded by increment id'
     *
     * @return Mage_Sales_Model_Order
     */
    protected function _getOrder()
    {
        if (empty($this->_order)) {
            $orderId = $this->getRequest()->getParam('orderID');
            $this->_order = Mage::getModel('sales/order');
            $this->_order->loadByIncrementId($orderId);
        }
        return $this->_order;
    }

    /**
     * Validation of incoming Postfinance data
     *
     * @return bool
     */
    protected function _validatePostfinanceData()
    {
        if ($this->_getApi()->getDebug()) {
            $debug = Mage::getModel('postfinance/api_debug')
                ->setDir('in')
                ->setUrl($this->getRequest()->getPathInfo())
                ->setData('data',http_build_query($this->getRequest()->getParams()))
                ->save();
        }

        $params = $this->getRequest()->getParams();
        $secureKey = $this->_getApi()->getConfig()->getShaInCode();
        $secureSet = $this->_getSHAInSet($params, $secureKey);

        if (Mage::helper('postfinance')->shaCryptValidation($secureSet, $params['SHASIGN'])!=true) {
            $this->_getCheckout()->addError($this->__('Hash is not valid'));
            return false;
        }

        $order = $this->_getOrder();
        if (!$order->getId()){
            $this->_getCheckout()->addError($this->__('Order is not valid'));
            return false;
        }

        return true;
    }

    /**
     * Load place from layout to make POST on postfinance
     */
    public function placeformAction()
    {
        $lastIncrementId = $this->_getCheckout()->getLastRealOrderId();
        if ($lastIncrementId) {
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($lastIncrementId);
            if ($order->getId()) {
                $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage_Postfinance_Model_Api::PENDING_POSTFINANCE_STATUS, Mage::helper('postfinance')->__('Start postfinance processing'));
                $order->save();

                if ($this->_getApi()->getDebug()) {
                    $debug = Mage::getModel('postfinance/api_debug')
                        ->setDir('out')
                        ->setUrl($this->getRequest()->getPathInfo())
                        ->setData('data', http_build_query($this->_getApi()->getFormFields($order)))
                        ->save();
                }
            }
        }

        $this->_getCheckout()->getQuote()->setIsActive(false)->save();
        $this->_getCheckout()->setPostfinanceQuoteId($this->_getCheckout()->getQuoteId());
        $this->_getCheckout()->setPostfinanceLastSuccessQuoteId($this->_getCheckout()->getLastSuccessQuoteId());
        $this->_getCheckout()->clear();

        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Display our pay page, need to postfinance payment with external pay page mode     *
     */
    public function paypageAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Action to control postback data from postfinance
     *
     */
    public function postBackAction()
    {
        if (!$this->_validatePostfinanceData()) {
            $this->getResponse()->setHeader("Status", "404 Not Found");
            return false;
        }

        $this->_postfinanceProcess();
    }

    /**
     * Action to process postfinance offline data
     *
     */
    public function offlineProcessAction()
    {
        if (!$this->_validatePostfinanceData()) {
            $this->getResponse()->setHeader("Status","404 Not Found");
            return false;
        }
        $this->_postfinanceProcess();
    }

    /**
     * Made offline postfinance data processing, depending of incoming statuses
     */
    protected function _postfinanceProcess()
    {
        $status = $this->getRequest()->getParam('STATUS');
        switch ($status) {
            case Mage_Postfinance_Model_Api::POSTFINANCE_AUTHORIZED :
            case Mage_Postfinance_Model_Api::POSTFINANCE_AUTH_PROCESSING:
            case Mage_Postfinance_Model_Api::POSTFINANCE_PAYMENT_REQUESTED_STATUS :
                $this->_acceptProcess();
                break;
            case Mage_Postfinance_Model_Api::POSTFINANCE_AUTH_REFUZED:
            case Mage_Postfinance_Model_Api::POSTFINANCE_PAYMENT_INCOMPLETE:
            case Mage_Postfinance_Model_Api::POSTFINANCE_TECH_PROBLEM:
                $this->_declineProcess();
                break;
            case Mage_Postfinance_Model_Api::POSTFINANCE_AUTH_UKNKOWN_STATUS:
            case Mage_Postfinance_Model_Api::POSTFINANCE_PAYMENT_UNCERTAIN_STATUS:
                $this->_exceptionProcess();
                break;
            default:
                //all unknown transaction will accept as exceptional
                $this->_exceptionProcess();
        }
    }

    /**
     * when payment gateway accept the payment, it will land to here
     * need to change order status as processed postfinance
     * update transaction id
     *
     */
    public function acceptAction()
    {
        if (!$this->_validatePostfinanceData()) {
            $this->_redirect('checkout/cart');
            return;
        }
        $this->_postfinanceProcess();
    }

    /**
     * Process success action by accept url
     */
    protected function _acceptProcess()
    {
        $params = $this->getRequest()->getParams();
        $order = $this->_getOrder();

        $this->_getCheckout()->setLastSuccessQuoteId($order->getQuoteId());

        $this->_prepareCCInfo($order, $params);
        $order->getPayment()->setTransactionId($params['PAYID']);

        try{
            if ($this->_getApi()->getPaymentAction()==Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE) {
                $this->_processDirectSale();
            } else {
                $this->_processAuthorize();
            }
        }catch(Exception $e) {
            $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Order can\'t save'));
            $this->_redirect('checkout/cart');
            return;
        }
    }

    /**
     * Process Configured Payment Action: Direct Sale, create invoce if state is Pending
     *
     */
    protected function _processDirectSale()
    {
        $order = $this->_getOrder();
        $status = $this->getRequest()->getParam('STATUS');
        try{
            if ($status ==  Mage_Postfinance_Model_Api::POSTFINANCE_AUTH_PROCESSING) {
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Postfinance_Model_Api::WAITING_AUTHORIZATION, Mage::helper('postfinance')->__('Authorization Waiting from Postfinance'));
                $order->save();
            }elseif ($order->getState()==Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                if ($status ==  Mage_Postfinance_Model_Api::POSTFINANCE_AUTHORIZED) {
                    if ($order->getStatus() != Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Postfinance_Model_Api::PROCESSING_POSTFINANCE_STATUS, Mage::helper('postfinance')->__('Processed by Postfinance'));
                    }
                } else {
                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Postfinance_Model_Api::PROCESSED_POSTFINANCE_STATUS, Mage::helper('postfinance')->__('Processed by Postfinance'));
                }

                if (!$order->getInvoiceCollection()->getSize()) {
                    $invoice = $order->prepareInvoice();
                    $invoice->register();
                    $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID);
                    $invoice->getOrder()->setIsInProcess(true);

                    $transactionSave = Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
                    $order->sendNewOrderEmail();
                }
            } else {
                $order->save();
            }
            $this->_redirect('checkout/onepage/success');
            return;
        } catch (Exception $e) {
            $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Order can\'t save'));
            $this->_redirect('checkout/cart');
            return;
        }
    }

    /**
     * Process Configured Payment Actions: Authorized, Default operation
     * just place order
     */
    protected function _processAuthorize()
    {
        $order = $this->_getOrder();
        $status = $this->getRequest()->getParam('STATUS');
        try {
            if ($status ==  Mage_Postfinance_Model_Api::POSTFINANCE_AUTH_PROCESSING) {
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Postfinance_Model_Api::WAITING_AUTHORIZATION, Mage::helper('postfinance')->__('Authorization Waiting from Postfinance'));
            } else {
                //to send new order email only when state is pending payment
                if ($order->getState()==Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                    $order->sendNewOrderEmail();
                }
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Postfinance_Model_Api::PROCESSED_POSTFINANCE_STATUS, Mage::helper('postfinance')->__('Processed by Postfinance'));
            }
            $order->save();
            $this->_redirect('checkout/onepage/success');
            return;
        } catch(Exception $e) {
            $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Order can\'t save'));
            $this->_redirect('checkout/cart');
            return;
        }
    }

    /**
     * We get some CC info from postfinance, so we must save it
     *
     * @param Mage_Sales_Model_Order $order
     * @param array $ccInfo
     *
     * @return Mage_Postfinance_ApiController
     */
    protected function _prepareCCInfo($order, $ccInfo)
    {
        $order->getPayment()->setCcOwner($ccInfo['CN']);
        $order->getPayment()->setCcNumberEnc($ccInfo['CARDNO']);
        $order->getPayment()->setCcLast4(substr($ccInfo['CARDNO'], -4));
        $order->getPayment()->setCcExpMonth(substr($ccInfo['ED'], 0, 2));
        $order->getPayment()->setCcExpYear(substr($ccInfo['ED'], 2, 2));
        return $this;
    }


    /**
     * the payment result is uncertain
     * exception status can be 52 or 92
     * need to change order status as processing postfinance
     * update transaction id
     *
     */
    public function exceptionAction()
    {
        if (!$this->_validatePostfinanceData()) {
            $this->_redirect('checkout/cart');
            return;
        }
        $this->_exceptionProcess();
    }

    /**
     * Process exception action by postfinance exception url
     */
    public function _exceptionProcess()
    {
        $params = $this->getRequest()->getParams();
        $order = $this->_getOrder();

        $exception = '';
        switch($params['STATUS']) {
            case Mage_Postfinance_Model_Api::POSTFINANCE_PAYMENT_UNCERTAIN_STATUS :
                $exception = Mage::helper('postfinance')->__('Payment uncertain: A technical problem arose during payment process, giving unpredictable result');
                break;
            case Mage_Postfinance_Model_Api::POSTFINANCE_AUTH_UKNKOWN_STATUS :
                $exception = Mage::helper('postfinance')->__('Authorization not known: A technical problem arose during authorization process, giving unpredictable result');
                break;
            default:
                $exception = Mage::helper('postfinance')->__('Unknown exception');
        }

        if (!empty($exception)) {
            try{
                $this->_getCheckout()->setLastSuccessQuoteId($order->getQuoteId());
                $this->_prepareCCInfo($order, $params);
                $order->getPayment()->setLastTransId($params['PAYID']);
                //to send new order email only when state is pending payment
                if ($order->getState()==Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                    $order->sendNewOrderEmail();
                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Postfinance_Model_Api::PROCESSING_POSTFINANCE_STATUS, $exception);
                } else {
                    $order->addStatusToHistory(Mage_Postfinance_Model_Api::PROCESSING_POSTFINANCE_STATUS, $exception);
                }
                $order->save();
            }catch(Exception $e) {
                $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Order can not be save for system reason'));
            }
        } else {
            $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Exception not defined'));
        }

        $this->_redirect('checkout/onepage/success');
    }

    /**
     * when payment got decline
     * need to change order status to cancelled
     * take the user back to shopping cart
     *
     */
    public function declineAction()
    {
        if (!$this->_validatePostfinanceData()) {
            $this->_redirect('checkout/cart');
            return;
        }
        $this->_getCheckout()->setQuoteId($this->_getCheckout()->getPostfinanceQuoteId());
        $this->_declineProcess();
        return $this;
    }

    /**
     * Process decline action by postfinance decline url
     */
    protected function _declineProcess()
    {
        $status     = Mage_Postfinance_Model_Api::DECLINE_POSTFINANCE_STATUS;
        $comment    = Mage::helper('postfinance')->__('Declined Order on postfinance side');
        $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Payment transaction has been declined.'));
        $this->_cancelOrder($status, $comment);
    }

    /**
     * when user cancel the payment
     * change order status to cancelled
     * need to rediect user to shopping cart
     *
     * @return Mage_Postfinance_ApiController
     */
    public function cancelAction()
    {
        if (!$this->_validatePostfinanceData()) {
            $this->_redirect('checkout/cart');
            return;
        }
        $this->_getCheckout()->setQuoteId($this->_getCheckout()->getPostfinanceQuoteId());
        $this->_cancelProcess();
        return $this;
    }

    /**
     * Process cancel action by cancel url
     *
     * @return Mage_Postfinance_ApiController
     */
    public function _cancelProcess()
    {
        $status     = Mage_Postfinance_Model_Api::CANCEL_POSTFINANCE_STATUS;
        $comment    = Mage::helper('postfinance')->__('Order canceled on postfinance side');
        $this->_cancelOrder($status, $comment);
        return $this;
    }

    /**
     * Cancel action, used for decline and cancel processes
     *
     * @return Mage_Postfinance_ApiController
     */
    protected function _cancelOrder($status, $comment='')
    {
        $order = $this->_getOrder();
        try{
            $order->cancel();
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, $status, $comment);
            $order->save();
        }catch(Exception $e) {
            $this->_getCheckout()->addError(Mage::helper('postfinance')->__('Order can not be canceled for system reason'));
        }

        $this->_redirect('checkout/cart');
        return $this;
    }

    /**
     * Return set of data which is ready for SHA crypt
     *
     * @param array $data
     * @param string $key
     *
     * @return string
     */
    protected function _getSHAInSet($params, $key)
    {
        return $this->getRequest()->getParam('orderID') .
               $this->getRequest()->getParam('currency') .
               $this->getRequest()->getParam('amount') .
               $this->getRequest()->getParam('PM') .
               $this->getRequest()->getParam('ACCEPTANCE') .
               $this->getRequest()->getParam('STATUS') .
               $this->getRequest()->getParam('CARDNO') .
               $this->getRequest()->getParam('PAYID') .
               $this->getRequest()->getParam('NCERROR') .
               $this->getRequest()->getParam('BRAND') . $key;
    }
}
