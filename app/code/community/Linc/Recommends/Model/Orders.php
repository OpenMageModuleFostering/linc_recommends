<?php
require_once "Linc/Recommends/common.php";

class Linc_Recommends_Model_Orders
{
	public $client = null;
	public $queue = null;
	public $logname = 'orderupload.log';
	
	public function log($msg)
	{
	    if (DBGLOG) Mage::log($msg, null, $this->logname, true);
        #print("<p>$msg</p>");
	}

    public function sendorders()
    {
        $this->log("Beginning sendOrders");
        
        $stores = Mage::app()->getStores();
        foreach ($stores as $store)
        {
            $name = $store->getName();
            $this->log("sending orders for $name");
            
	        $store_id = $store->getId();
	        
            # get access key
            $resource = Mage::getSingleton('core/resource');
            $read = $resource->getConnection('core_read');
            $configDataTable = $read->getTableName('core_config_data');

            $accessToken = '';
            $select = $read->select()
                ->from(array('cd'=>$configDataTable))
                ->where('cd.scope=?', 'store')
                ->where("cd.scope_id=?", $store_id)
                ->where("cd.path=?", 'linc_access_key');
            $rows = $read->fetchAll($select);

            if (count($rows) > 0)
            {
                $accessToken = $rows[0]['value'];
            }

		    if ($accessToken != null)
		    {
		        $this->log("Got access key - $accessToken");
		        
			    $last_order = 0;
                $select = $read->select()
                    ->from(array('cd'=>$configDataTable))
                    ->where('cd.scope=?', 'store')
                    ->where("cd.scope_id=?", $store_id)
                    ->where("cd.path=?", 'linc_last_order_sent');
                $rows = $read->fetchAll($select);

                if (count($rows) > 0)
                {
                    $last_order = $rows[0]['value'];
                }
			
                $this->log("Starting with order # $last_order");
			
		        $orders = Mage::getModel('sales/order')->getCollection();
                $orders->addFieldToFilter('store_id', $store_id);
                $orders->addAttributeToFilter('entity_id', array('gt' => $last_order));
                $orders->setOrder('entity_id', 'ASC');
                $orders->getSelect()->limit(50);                              // limit number of results returned
                
                $this->log("Collection ready");
               
                foreach ($orders as $order)
                {
                    $last_order = $order->getEntityId();

                    $temp = $order->getName();
                    $this->log("Sending order $temp");
                    $post_data_json = $this->buildJson($order);
                    
                    if ($post_data_json != "")
                    {
                        $this->sendOrder($accessToken, $post_data_json);
                    }
		        }

		        Mage::getConfig()->saveConfig('linc_last_order_sent', $last_order, 'store', $store_id);
                Mage::getConfig()->reinit();
                Mage::app()->reinitStores();
 		    }
		    else
		    {
		        $this->log('No access token');
		    }
		}
    }

	public function sendOrder($accessToken, $postData)
	{
		if ($this->client == null)
		{
			$this->log("Connecting to Linc Care");
			$this->connectToLincCare($accessToken);
			if ($this->client != null && $this->queue != null)
			{
    			$this->log("Processing the queue");
				$sendQueue = $this->queue;
				unset($this->queue);
				
				foreach ($sendQueue as $data)
				{
					$this->sendOrder($accessToken, $data);
				}
			}
			else
			{
    			$this->log("Saving to queue");
				if ($this->queue == null)
				{
					$this->queue = array();
				}
				
				array_push($this->queue, $postData);
			}
		}

		if ($this->client != null)
		{
			$this->log("Building request");
			
			$this->client->setRawData($postData, 'application/json');
			
			$response = $this->client->request();
			
			$temp = $response->getStatus();
			$this->log("Linc_Care HTTP response $temp");
			
			if (in_array($temp, array(200, 201, 401)))
			{
				$temp = $response->getHeadersAsString();
				$this->log("Linc_Care Response Headers:<br/>$temp");
				$temp = $response->getBody();
				$this->log("Linc_Care Repsonse Body:<br/>$temp");
			}
			else
			{
			    $adapter = $this->client->getAdapter();
			    $adapter->close();
				$this->client = null;
				array_push($this->queue, $postData);
			}
		}
	}
	
	public function connectToLincCare($accessToken)
	{
        $protocol = SERVER_PROTOCOL;
        $url = SERVER_PATH;

		$this->client = new Zend_Http_Client();
		$this->client->setUri("$protocol://pub-api.$url/v1/order");
		
		$this->client->setConfig(array(
            'maxredirects' => 0,
            'timeout'      => 30,
            'keepalive'    => true,
            'adapter'      => 'Zend_Http_Client_Adapter_Socket'));
	    
		$this->client->setMethod(Zend_Http_Client::POST);
		$this->client->setHeaders(array(
			'Authorization' => 'Bearer '.$accessToken,
			'Content-Type' => 'application/json'));
	}
		
	public function buildJson($order)
	{
		$this->log("buildJson started");

		$orderdata  = $order->getData();
		$this->log("buildJson Got data");
		
		$b_addr = $order->getBillingAddress();
		if (DBGLOG) 
		{
			$temp = json_encode($b_addr->getData());
			$this->log("buildJson got billing address $temp");
		}
	
		$s_addr = $order->getShippingAddress();
		if (DBGLOG && $s_addr != null)
		{
			$temp = json_encode($s_addr->getData());
			$this->log("buildJson got shipping address $temp");
		}
		else
		{
			/* use billing address for shipping address when the purchase is a download. */
			$s_addr = $b_addr;
		}
		
		$phone = $b_addr->getTelephone();
		$this->log("buildJson got phone $phone");
		
		$items = $order->getItemsCollection();
		$this->log("buildJson got item collection");
		
		$dataitems = array();
		foreach ($items as $item)
		{
			$product = Mage::getModel('catalog/product')->load($item->getProduct()->getId());

#			if ($product->isVisibleInSiteVisibility())
#			{
			  $dataitem = array(
				  'title'       => $item->getName(),
				  'description' => $item->getDescription(),
				  'thumbnail'   => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product' . $product->getImage(),
				  'price'       => $item->getPrice(),
				  'weight'      => $item->getWeight());
				
			  $temp = json_encode($dataitem);
			  $this->log("buildJson built an item $temp");
			
			  array_push($dataitems, $dataitem);
#			}
		}
		
		$this->log("buildJson built items");
		
		$user = array (
			'user_id'    => $order->getCustomerId(),
			'first_name' => $order->getCustomerFirstname(),
			'last_name'  => $order->getCustomerLastname(),
			'email'      => $order->getCustomerEmail(),
			'phone'      => $phone);
			
		if (DBGLOG)
		{
			$temp = json_encode($user);
			$this->log("buildJson built user $temp");
		}
		
		#$country = Mage::getModel('directory/country')->loadByCode($b_addr->getCountry());
		$addrB = array(
			'address'		=> $b_addr->getStreet1(),
			'address2'	    => $b_addr->getStreet2(),
			'city'			=> $b_addr->getCity(),
			'state'			=> $b_addr->getRegion(),
			'country_code'	=> $b_addr->getCountry(),
			#'country'		=> $country->getName(),
			'zip'			=> $b_addr->getPostcode());
			
		if (DBGLOG)
		{
			$temp = json_encode($addrB);
			$this->log("buildJson built billing address $temp");
		}
		
		#$country = Mage::getModel('directory/country')->loadByCode($s_addr->getCountry());
		$addrS = array(
			'address'		=> $s_addr->getStreet1(),
			'address2'  	=> $s_addr->getStreet2(),
			'city'			=> $s_addr->getCity(),
			'state'			=> $s_addr->getRegion(),
			'country_code'	=> $s_addr->getCountry(),
			#'country'		=> $country->getName(),
			'zip'			=> $s_addr->getPostcode());

		if (DBGLOG)
		{
			$temp = json_encode($addrS);
			$this->log("buildJson built shipping address $temp");
		}
		
		$dataorder = array(
			'user' => $user,
			'order_code' => $order->getIncrementId(),
			'billing_address' => $addrB,
			'shipping_address' => $addrS,
			'purchase_date' => $order->getUpdatedAt(),
			'grand_total' => $order->getGrandTotal(),
			'total_taxes' => $order->getTaxAmount(),
			'products' => $dataitems);
		
		$postdata = json_encode($dataorder);
		$this->log($postdata);

		$this->log("buildJson ended");
		
		return $postdata;
	}
}

?>
