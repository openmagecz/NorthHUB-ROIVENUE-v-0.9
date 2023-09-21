<?php

/**
 * 
 * Magento 1.X to Roivenue BI integration
 * 
 * This class integrates MAGENTO open source (ver. 1.9.x) into ROIVENUE business intelligence.
 * Creates XML feed with orders and upload the feed to cloud (MS Azure) file storage.
 * 
 * All configuration related to the XML feed and MS Azure cloud access is actually hardcoded
 * into the class constants. Previously developed by NorthHUB for HARTMANN - RICO a.s. - Kneipp store.
 * 
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is
 * available through the world-wide-web at https://opensource.org/licenses/osl-3.0.php
 * 
 * @category    Northhub
 * @package     Northhub_Roivenue
 * @author      Stanislav Puffler <stanislav.puffler@northhub.cz>
 * @copyright   2018 NorthHUB 
 * @link        http://magento.northhub.cz
 * @license     https://opensource.org/licenses/osl-3.0.php
 * @version     0.9
 * 
 */

class Northhub_Roivenue_Model_Cron extends Mage_Core_Model_Abstract
{
    
    // SET APPROPRIATE STORE VIEW ID 
    // WITHIN YOUR MAGENTO AND BASE
    // CURRENCY FOR THAT STORE VIEW
    const STOREID       = 1;
    const WEBCURRENCY   = "CZK";
    
    // XML FEED CONSTANTS
    const WEBCODE       = "kneippcom";
    const WEBSITE       = "kneipp.cz";
    const WEEKS         = 13;
    
    // MS AZURE CLOUD STORAGE ACCESS
    const ACCOUNTNAME   = "youraccountname";
    const ACCOUNTKEY    = "yOuRaCc0uNtK3Y";
    const SHARENAME     = "roi-share";
    const SHAREFOLDER   = "export";
    
    // LOG FILE
    const ROIVENUE_LOG  = "roivenue.log";
    
    private $dateFrom;
    private $dateTo;
    private $feedFolderPath;
    private $feedFileName;
    private $ordersCollection;
    
    /**
     * 
     * Main public function as an entry point into the Roivenue module
     * for regular Magento CRON calls configured in /etc/config.xml file.
     * 
     * Creates whole XML feed, saves it and sends to a remote location.
     *  
     */

	public function export()
	{
	    /* Log basic local date and time info */
	    $this->logDateTimeEnv();
		
		/* Set date range for orders exported to XML feed */
		$this->setDateRange();
		
		/* Set store id for Czech store view */
		$this->storeId = self::STOREID;
		
		/* Set orders collection within the date range */
		$this->setOrdersCollection($this->dateFrom, $this->dateTo);
		
		/* Set feed file name, folder path and create folder if necessary */		
		$this->feedFileName = $this->constructFeedFileName($this->getOrderStartDate(), $this->getOrderEndDate());
		$this->feedFolderPath = $this->constructFeedFolderPath();
		$this->createFeedFolder($this->feedFolderPath);
		
		/* Log start and end date for orders collection range */
		Mage::log('OrderStartDate: ' . $this->ordersCollection->getFirstItem()->getCreatedAt(), Zend_Log::INFO, self::ROIVENUE_LOG);
		Mage::log('OrderEndDate: ' . $this->ordersCollection->getLastItem()->getCreatedAt(), Zend_Log::INFO, self::ROIVENUE_LOG);
		
		/* Create content of XML feed and save to a XML file */
		$feedContents = $this->constructFeedHeader();
		$feedContents .= $this->constructFeedContent($this->getOrdersCollection(), $this->getEncryptionKey());
		$feedContents .= $this->constructFeedFooter();
		$this->saveFeedFile($this->feedFolderPath . $this->feedFileName, $feedContents);
		
		/* Upload the XML file to Roivenue´s remote filesystem */
		$this->uploadFeedFile($this->feedFileName, $feedContents, self::ACCOUNTNAME, self::ACCOUNTKEY);
	}
	
	/**
	 * 
	 * Log information about the local date and time.  
	 * 
	 */
	protected function logDateTimeEnv() {
	    $currentTimestamp = time();
	    $currentMagentoTimestamp = Mage::getModel('core/date')->timestamp(time());
	    Mage::log('Default timezone for PHP is ' . date_default_timezone_get() . ' and for MAGENTO ' . Mage::getStoreConfig('general/locale/timezone'), Zend_Log::INFO, self::ROIVENUE_LOG);
	    Mage::log('PHP timestamp: ' . $currentTimestamp . ' | MAGENTO timestamp: ' . $currentMagentoTimestamp, Zend_Log::INFO, self::ROIVENUE_LOG);
	    Mage::log('PHP datetime: ' . date('Y-m-d H:i:s', $currentTimestamp) . ' | MAGENTO datetime: ' . date('Y-m-d H:i:s', $currentMagentoTimestamp), Zend_Log::INFO, self::ROIVENUE_LOG);
	}
	
	/**
	 * 
	 * Get encryption key from ASCII safe string using the most secure class Defuse/Crypto.
	 * 
	 * @return /Defuse/Crypto/Key Encryption key
	 * 
	 */
	private function getEncryptionKey() {
	    require_once(Mage::getBaseDir('lib') . '/Defuse/defuse-crypto.phar');
	    $secret = file_get_contents(Mage::getBaseDir('app') . "/etc/secret.key");
	    return \Defuse\Crypto\Key::loadFromAsciiSafeString($secret);
	}
	
	/**
	 * 
	 * Set the correct date range for orders export. The range is taken from 
	 * Roivenue documentation. Roivenue needs all order data 13 weeks back.
	 * 
	 */
	protected function setDateRange() {
	    $this->dateFrom = $this->getDateRangeFrom();
	    $this->dateTo = $this->getDateRangeTo();
	}
	
	/**
	 * 
	 * Get the start date of the right Roivenue orders feed range.
	 * 
	 * @return string Start date of the orders range
	 * 
	 */
	protected function getDateRangeFrom() {
	    return date('Y-m-d', Mage::getModel('core/date')->timestamp(strtotime("yesterday -" . self::WEEKS . " weeks")));
	}
	
	/**
	 * 
	 * Get the end date of the right Roivenue orders feed range.
	 *
	 * @return string End date of the orders range
	 * 
	 */
	protected function getDateRangeTo() {
	    return date('Y-m-d', Mage::getModel('core/date')->timestamp(strtotime("today")));
	}
	
	/**
	 * 
	 * Set Magento orders collection within the date range.
	 * 
	 * @param string $from
	 *     Start date of the orders range
	 * @param string $to
	 *     End date of the orders range
	 * 
	 */
	private function setOrdersCollection($from, $to) {
	    $orders = Mage::getModel('sales/order')->getCollection()
	    ->addAttributeToSelect('*')
	    ->addAttributeToFilter('created_at', array('from'=>$from, 'to'=>$to))
	    ->addAttributeToFilter('store_id', $this->storeId)
	    ->setOrder('created_at', 'ASC');
	    $this->ordersCollection = $orders;
	}
	
	/**
	 * 
	 * Get Magento orders collection within the date range.
	 * 
	 * @return object Mage_Sales_Model_Resource_Order_Collection Magento orders collection object
	 * 
	 */
	private function getOrdersCollection() {
	    return $this->ordersCollection;
	}
	
	/**
	 * 
	 * Get the size of orders collection.
	 * 
	 * @param Mage_Sales_Model_Resource_Order_Collection
	 *     Magento orders collection
	 * 
	 * @return int Count of all orders within orders collection
	 * 
	 */
	protected function getSizeOfOrdersCollection($orders) {
	    return sizeof($orders);
	}
	
	/**
	 *
	 * Get ID of latest order within orders collection.
	 *
	 * @param Mage_Sales_Model_Resource_Order_Collection
	 *     Magento orders collection
	 * 
	 * @return int ID of the latest order within orders collection
	 *
	 */
	protected function getLastestOrderId($orders) {
	    return $orders->getLastItem()->getIncrementId();
	}
	
	/**
	 *
	 * Get ID of oldest order within orders collection.
	 *
	 * @param Mage_Sales_Model_Resource_Order_Collection
	 *     Magento orders collection
	 * 
	 * @return int ID of the oldest order within orders collection
	 *
	 */
	protected function getOldestOrderId($orders) {
	    return $orders->getFirstItem()->getIncrementId();
	}
	
	/**
	 * 
	 * Get XML feed file name constructed using the date of latest and oldest order.
	 * 
	 * @param string $startdate
	 *     Datetime of the oldest order
	 * @param string $enddate
	 *     Datetime of the latest order
	 * 
	 * @return string XML feed file name for this day
	 * 
	 */
	protected function constructFeedFileName($startdate, $enddate) {
	    $filename = "oms-extract-orders_" . self::WEBCODE . "_" . $startdate . "-" . $enddate . ".xml";
	    return $filename;
	}
	
	/**
	 *
	 * Get date of the very first order within the Magento orders collection.
	 *
	 * @return string Date of the first order within collection
	 *
	 */
	protected function getOrderStartDate() {
	    $startDate = $this->ordersCollection->getFirstItem()->getCreatedAt();
	    if(empty($startDate)) $startDate = $this->dateFrom;
	    return date('Y-m-d', strtotime($startDate));
	}
	
	/**
	 *
	 * Get date of the very last order within the Magento orders collection.
	 *
	 * @return string Date of the last order within collection
	 * 
	 */
	protected function getOrderEndDate() {
	    $endDate = $this->ordersCollection->getLastItem()->getCreatedAt();
	    if(empty($endDate)) $endDate = $this->dateTo;
	    return date('Y-m-d', strtotime($endDate));
	}
	
	/**
	 * 
	 * Get XML feed header
	 * 
	 * @return string XML file header
	 * 
	 */
	protected function constructFeedHeader() {
	    $startdate = $this->getOrderStartDate();
	    $enddate = $this->getOrderEndDate();
	    $xmlfile = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
	    $xmlfile .= "<Orders version=\"5.2\" propertyCode=\"" . self::WEBCODE ."\" startDate=\"" . $startdate . "\" endDate=\"" . $enddate . "\">\n";	    
	    return $xmlfile;
	}
	
	/**
	 * 
	 * Construct the very end of XML feed contents.
	 * 
	 * @return string End of orders
	 * 
	 */
	protected function constructFeedFooter() {
	    return "</Orders>\n";
	}
	
	/**
	 * 
	 * Constructs orders content in XML file from given orders collection.
	 * 
	 * @param Mage_Sales_Model_Resource_Order_Collection $orders
	 *     Magento orders collection object
	 * @param object $key
	 *     Defuse/Crypto encryption key
	 * 
	 * @return string Contents of XML feed order items
	 * 
	 */
	protected function constructFeedContent($orders, $key) {

	    $xmlfile = "";
	    
	    Mage::log('There are ' . $this->getSizeOfOrdersCollection($orders) . ' orders left to be processed.', Zend_Log::INFO, self::ROIVENUE_LOG);
	    
	    foreach($orders as $order)
	    {
	        $storeId = $order->getStoreId();
	        
	        $itemline = "\t<Order>\n";
	        
	        $itemline .= "\t\t<Site>" . self::WEBSITE . "</Site>\n";
	        $itemline .= "\t\t<WebId>" . $order->getIncrementId() . "</WebId>\n";
	        $itemline .= "\t\t<SalesChannel>online</SalesChannel>\n";
	        $itemline .= "\t\t<OrderId>" . $order->getIncrementId() . "</OrderId>\n";
	        $itemline .= "\t\t<SourceSystem>MAGENTO " . Mage::getVersion() . "</SourceSystem>\n";
	        
	        // customer vars
	        $userEmail = strtolower(trim($order->getCustomerEmail()));
	        $userEmailDomain = substr(strrchr($userEmail, "@"), 1);
	        $userEmailHash = \Defuse\Crypto\Crypto::Encrypt($userEmail, $key);
	        
	        $customerId = $userEmailHash;
	        if($order->getCustomerId()) {
	            $customerId = $order->getCustomerId();
	        }
	        $userPhone = trim($order->getBillingAddress()->getTelephone());
	        $userPhoneHash = \Defuse\Crypto\Crypto::Encrypt($userPhone, $key);
	        
	        $userSegment = "NOT LOGGED IN";
	        if ($order->getCustomerGroupId()) {
	            $customerGroup = Mage::getModel('customer/group')->load($order->getCustomerGroupId());
	            $userSegment = strtoupper($customerGroup->getCustomerGroupCode());
	        }
	        $itemline .= "\t\t<UserId>" . $customerId . "</UserId>\n";
	        $itemline .= "\t\t<UserEmailDomain>" . $userEmailDomain . "</UserEmailDomain>\n";
	        $itemline .= "\t\t<UserEmailHash>" . $userEmailHash . "</UserEmailHash>\n";
	        $itemline .= "\t\t<UserPhoneHash>" . $userPhoneHash . "</UserPhoneHash>\n";
	        $itemline .= "\t\t<UserSegment>" . $userSegment . "</UserSegment>\n";
	        
	        // billing / shipping information
	        $address = $order->getShippingAddress();
	        $country = $address->getCountry();
	        $city = $address->getCity();
	        $postcode = $address->getData('postcode');
	        $street = $address->getStreetFull();
	        $itemline .= "\t\t<UserCountryCode>" . $country . "</UserCountryCode>\n";
	        $itemline .= "\t\t<UserCity>" . $city . "</UserCity>\n";
	        $itemline .= "\t\t<UserStreet>" . $street . "</UserStreet>\n";
	        $itemline .= "\t\t<UserPostalCode>" . $postcode . "</UserPostalCode>\n";
	        
	        $createdAt = str_replace(" ", "T", Mage::getModel('core/date')->date('Y-m-d H:i:s', strtotime($order->getCreatedAt())));
	        
	        if($order->getCustomerIsGuest()) {
	            $itemline .= "\t\t<UserCreatedAt>" . $createdAt . "</UserCreatedAt>\n";
	        } else {
	            $customer = Mage::getModel('customer/customer')->load($customerId);
	            $customerCreatedAt = str_replace(" ", "T", Mage::getModel('core/date')->date('Y-m-d H:i:s', strtotime($customer->getCreatedAt())));
	            $itemline .= "\t\t<UserCreatedAt>" . $customerCreatedAt . "</UserCreatedAt>\n";
	        }
	        
	        // detail order information
	        $_order = Mage::getModel('sales/order')->load($order->getIncrementId(), 'increment_id');
	        $orderStatusLabel = $order->getStatusLabel();
	        $orderStatus = $order->getStatus();
	        $itemline .= "\t\t<Status>" . $orderStatusLabel . "</Status>\n";
	        
	        if($orderStatus == "complete") {
	            $itemline .= "\t\t<Delivered>1</Delivered>\n";
	        } else {
	            $itemline .= "\t\t<Delivered>0</Delivered>\n";
	        }
	        
	        $paymentType = htmlspecialchars($order->getPayment()->getMethodInstance()->getTitle(), ENT_QUOTES, 'UTF-8');
	        $deliveryType = htmlspecialchars($order->getShippingDescription(), ENT_QUOTES, 'UTF-8');
	        
	        if(strlen($paymentType) > 50) {
	            $paymentType = mb_substr($paymentType, 0, 50);
	        }
	        
	        if(strlen($deliveryType) > 50) {
	            $deliveryType = mb_substr($deliveryType, 0, 50);
	        }
	        
	        $itemline .= "\t\t<PaymentType>" . $paymentType . "</PaymentType>\n";
	        $itemline .= "\t\t<DeliveryType>" . $deliveryType . "</DeliveryType>\n";
	        if($order->getCustomerIsGuest()) {
	            $itemline .= "\t\t<ProcessingType>no-login</ProcessingType>\n";
	        } else {
	            $itemline .= "\t\t<ProcessingType>login</ProcessingType>\n";
	        }
	        
	        $modifiedAt = str_replace(" ", "T", Mage::getModel('core/date')->date('Y-m-d H:i:s', strtotime($order->getStatusHistoryCollection()->getFirstItem()->getCreatedAt())));
	        $itemline .= "\t\t<CreatedAt>" . $createdAt . "</CreatedAt>\n";
	        $itemline .= "\t\t<ModifiedAt>" . $modifiedAt . "</ModifiedAt>\n";
	        $itemline .= "\t\t<OrderedAt>" . $createdAt . "</OrderedAt>\n";
	        
	        $itemline .= "\t\t<CurrencyCode>" . $order->getOrderCurrencyCode() . "</CurrencyCode>\n";
	        $itemline .= "\t\t<Total>" . $order->getGrandTotal() . "</Total>\n";
	        $itemline .= "\t\t<DeliveryCost>0</DeliveryCost>\n";
	        $itemline .= "\t\t<PaymentCost>0</PaymentCost>\n";
	        $itemline .= "\t\t<ProcessingCost>0</ProcessingCost>\n";
	        
	        $subtotal = $order->getSubtotal();
	        $discount = str_replace("-", "", $order->getDiscountAmount());
	        $revenue = $subtotal - $discount;
	        $itemline .= "\t\t<Revenue>" . $revenue . "</Revenue>\n";
	        $itemline .= "\t\t<ProductsCost>0</ProductsCost>\n";
	        $itemline .= "\t\t<Profit>0</Profit>\n";
	        $itemline .= "\t\t<Discount>" . $discount . "</Discount>\n";
	        $itemline .= "\t\t<Delivery>" . $order->getShippingInclTax() . "</Delivery>\n";
	        $itemline .= "\t\t<Surcharge>0</Surcharge>\n";
	        
	        $tax = 0;
	        foreach ($order->getAllItems() as $item) {
	            $tax = $tax + $item->getTaxAmount();
	        }
	        $itemline .= "\t\t<Tax>" . $tax . "</Tax>\n";
	        $itemline .= "\t</Order>\n";
	        
	        $xmlfile .= $itemline;
	   }
	   
	   return $xmlfile;
	   
	}
	
	/**
	 * 
	 * Construct feed folder path, where the final XML feed should be saved.
	 * 
	 * @return string Full filesystem path to the roivenue export folder
	 * 
	 */
	protected function constructFeedFolderPath() {
	    return Mage::getBaseDir() . DS . 'var' . DS . 'export' . DS . 'roivenue' . DS;
	}
	
	/**
	 * 
	 * Create export folder if do not exists.
	 * 
	 */
	protected function createFeedFolder($folderPath) {
	    if(!file_exists($folderPath)) {
	       $file = new Varien_Io_File();
	       $feedFolder = $file->mkdir($folderPath);
	       if (!$feedFolder) {
	           Mage::log('Folder ' . $folderPath . ' for Roivenue XML feed exports do not exists!', Zend_Log::ERR, self::ROIVENUE_LOG);
	       }
	    }
	}
	
	/**
	 * 
	 * Save XML feed to local file.
	 * 
	 * @param string $filepath
	 *     File path
	 * @param string $feed
	 *     XML feed
	 * 
	 */
	protected function saveFeedFile($filepath, $feed) {
	    file_put_contents($filepath, $feed);
	}
	
	/**
	 *
	 * Uploads an XML feed to MS Windows Azure cloud file server.
	 *
	 * @param string $filePath
	 *     XML file path
	 * @param string $feedContents
	 *     Contents of the XML feed
	 * @param string $accountName
	 *     Auth name for MS Azure cloud storage
	 * @param string $accountKey
	 *     Auth key for MS Azure cloud storage
	 *
	 */
	
	protected function uploadFeedFile($fileName, $feedContents, $accountName, $accountKey) {
	    
	    // alternative PSR-4 autoloader for third party libraries without using Composer
	    require_once(Mage::getBaseDir('lib') . '/autoload.php');
	    	    
	    // create connectionString
	    $connectionString = "DefaultEndpointsProtocol=https;AccountName=" . $accountName . ";AccountKey=" . $accountKey;
	    
	    // prepare the REST API proxy
	    $fileRestProxy = MicrosoftAzure\Storage\File\FileRestProxy::createFileService($connectionString);
	    
	    // create remote file from existing content
	    $result = $fileRestProxy->createFileFromContent(
	       self::SHARENAME,
	       self::SHAREFOLDER . '/' . $fileName,
	        $feedContents,
	        null
	    );
	    
	}
	
}