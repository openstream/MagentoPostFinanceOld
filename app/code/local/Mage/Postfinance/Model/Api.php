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
 * Postfinance payment method model
 */
class Mage_Postfinance_Model_Api extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = 'postfinance';
    protected $_formBlockType = 'postfinance/form';
    protected $_infoBlockType = 'postfinance/info';
    protected $_config = null;

     /**
     * Availability options
     */
    protected $_isGateway               = false;
    protected $_canAuthorize            = true;
    protected $_canCapture              = false;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;

    /* Postfinance template modes */
    const TEMPLATE_POSTFINANCE            = 'postfinance';
    const TEMPLATE_MAGENTO          = 'magento';

    /* Postfinance payment process statuses */
    const PENDING_POSTFINANCE_STATUS      = 'pending_postfinance';
    const CANCEL_POSTFINANCE_STATUS       = 'cancel_postfinance';
    const DECLINE_POSTFINANCE_STATUS      = 'decline_postfinance';
    const PROCESSING_POSTFINANCE_STATUS   = 'processing_postfinance';
    const WAITING_AUTHORIZATION     = 'waiting_authorozation';
    const PROCESSED_POSTFINANCE_STATUS    = 'processed_postfinance';

    /* Postfinance responce statuses */
    const POSTFINANCE_PAYMENT_REQUESTED_STATUS    = 9;
    const POSTFINANCE_PAYMENT_PROCESSING_STATUS   = 91;
    const POSTFINANCE_AUTH_UKNKOWN_STATUS         = 52;
    const POSTFINANCE_PAYMENT_UNCERTAIN_STATUS    = 92;
    const POSTFINANCE_PAYMENT_INCOMPLETE          = 1;
    const POSTFINANCE_AUTH_REFUZED                = 2;
    const POSTFINANCE_AUTH_PROCESSING             = 51;
    const POSTFINANCE_TECH_PROBLEM                = 93;
    const POSTFINANCE_AUTHORIZED                  = 5;

    /* Layout of the payment method */
    const PMLIST_HORISONTAL_LEFT            = 0;
    const PMLIST_HORISONTAL                 = 1;
    const PMLIST_VERTICAL                   = 2;

    /* postfinance paymen action constant*/
    const POSTFINANCE_AUTHORIZE_ACTION = 'RES';
    const POSTFINANCE_AUTHORIZE_CAPTURE_ACTION = 'SAL';

    /**
     * Init Postfinance Api instance, detup default values
     *
     * @return Mage_Postfinance_Model_Api
     */
    public function __construct()
    {
        $this->_config = Mage::getSingleton('postfinance/config');
        return $this;
    }

    /**
     * Return postfinance config instance
     *
     * @return Mage_Postfinance_Model_Config
     */
    public function getConfig()
    {
        return $this->_config;
    }

    /**
     * Return debug flag by storeConfig
     *
     * @param int storeId
     * @return bool
     */
    public function getDebug($storeId=null)
    {
        return $this->getConfig()->getConfigData('debug_flag', $storeId);
    }

    /**
     * Flag witch prevent automatic invoice creation
     *
     * @return bool
     */
    public function isInitializeNeeded()
    {
        return true;
    }

    /**
     * Redirect url to postfinance submit form
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
		  Mage::log(Mage::getUrl('postfinance/api/placeform', array('_secure' => true)));
          return Mage::getUrl('postfinance/api/placeform', array('_secure' => true));
    }

    /**
     * Return payment_action value from config area
     *
     * @return string
     */
    public function getPaymentAction()
    {
        return $this->getConfig()->getConfigData('payment_action');
    }

    /**
     * Rrepare params array to send it to gateway page via POST
     *
     * @param Mage_Sales_Model_Order
     * @return array
     */
    public function getFormFields($order)
    {
        if (empty($order)) {
            if (!($order = $this->getOrder())) {
                return array();
            }
        }
        $billingAddress = $order->getBillingAddress();
        $formFields = array();
        $formFields['PSPID']    = $this->getConfig()->getPSPID();
        $formFields['orderID']  = $order->getIncrementId();
        $formFields['amount']   = round($order->getBaseGrandTotal()*100);
        $formFields['currency'] = Mage::app()->getStore()->getBaseCurrencyCode();
        $formFields['language'] = Mage::app()->getLocale()->getLocaleCode();

        $formFields['CN']       = $this->_translate($billingAddress->getFirstname().' '.$billingAddress->getLastname());
        $formFields['EMAIL']    = $order->getCustomerEmail();
        $formFields['ownerZIP'] = $billingAddress->getPostcode();
        $formFields['ownercty'] = $billingAddress->getCountry();
        $formFields['ownertown']= $this->_translate($billingAddress->getCity());
        $formFields['COM']      = $this->_translate($this->_getOrderDescription($order));
        $formFields['ownertelno']   = $billingAddress->getTelephone();
        $formFields['owneraddress'] =  $this->_translate(str_replace("\n", ' ',$billingAddress->getStreet(-1)));

        $paymentAction = $this->_getPostfinancePaymentOperation();
        if ($paymentAction ) {
            $formFields['operation'] = $paymentAction;
        }

        $secretCode = $this->getConfig()->getShaOutCode();
        $secretSet  = $formFields['orderID'] . $formFields['amount'] . $formFields['currency'] .
            $formFields['PSPID'] . $paymentAction . $secretCode;

        $formFields['SHASign']  = Mage::helper('postfinance')->shaCrypt($secretSet);

        $formFields['homeurl']          = $this->getConfig()->getHomeUrl();
        $formFields['catalogurl']       = $this->getConfig()->getHomeUrl();
        $formFields['accepturl']        = $this->getConfig()->getAcceptUrl();
        $formFields['declineurl']       = $this->getConfig()->getDeclineUrl();
        $formFields['exceptionurl']    = $this->getConfig()->getExceptionUrl();
        $formFields['cancelurl']        = $this->getConfig()->getCancelUrl();

        if ($this->getConfig()->getConfigData('template')=='postfinance') {
            $formFields['TP']= '';
            $formFields['PMListType'] = $this->getConfig()->getConfigData('pmlist');
        } else {
            $formFields['TP']= $this->getConfig()->getPayPageTemplate();
        }
        $formFields['TITLE']            = $this->_translate($this->getConfig()->getConfigData('html_title'));
        $formFields['BGCOLOR']          = $this->getConfig()->getConfigData('bgcolor');
        $formFields['TXTCOLOR']         = $this->getConfig()->getConfigData('txtcolor');
        $formFields['TBLBGCOLOR']       = $this->getConfig()->getConfigData('tblbgcolor');
        $formFields['TBLTXTCOLOR']      = $this->getConfig()->getConfigData('tbltxtcolor');
        $formFields['BUTTONBGCOLOR']    = $this->getConfig()->getConfigData('buttonbgcolor');
        $formFields['BUTTONTXTCOLOR']   = $this->getConfig()->getConfigData('buttontxtcolor');
        $formFields['FONTTYPE']         = $this->getConfig()->getConfigData('fonttype');
        $formFields['LOGO']             = $this->getConfig()->getConfigData('logo');
        return $formFields;
    }

    /**
     * to translate UTF 8 to ISO 8859-1
     * Postfinance system is only compatible with iso-8859-1 and does not (yet) fully support the utf-8
     */
    protected function _translate($text)
    {
        return htmlentities(iconv("UTF-8", "ISO-8859-1", $text));
    }

    /**
     * Get Postfinance Payment Action value
     *
     * @param string
     * @return string
     */
    protected function _getPostfinancePaymentOperation()
    {
        $value = $this->getPaymentAction();
        if ($value==Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE) {
            $value = Mage_Postfinance_Model_Api::POSTFINANCE_AUTHORIZE_ACTION;
        } elseif ($value==Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE) {
            $value = Mage_Postfinance_Model_Api::POSTFINANCE_AUTHORIZE_CAPTURE_ACTION;
        }
        return $value;
    }

    /**
     * get formated order description
     *
     * @param Mage_Sales_Model_Order
     * @return string
     */
    protected function _getOrderDescription($order)
    {
        $invoiceDesc = '';
        $lengs = 0;
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            //COM filed can only handle max 100
            if (Mage::helper('core/string')->strlen($invoiceDesc.$item->getName()) > 100) {
                break;
            }
            $invoiceDesc .= $item->getName() . ', ';
        }
        return Mage::helper('core/string')->substr($invoiceDesc, 0, -2);
    }
}
