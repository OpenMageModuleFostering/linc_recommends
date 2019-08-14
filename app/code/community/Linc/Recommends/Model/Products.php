<?php
require_once "Linc/Recommends/common.php";

class Linc_Recommends_Model_Products
{
	public $client = null;
	public $queue = null;
	public $logname = 'productupload.log';
	
	public function log($msg)
	{
	    if (DBGLOG) Mage::log($msg, null, $this->logname, true);
        #print("<p>$msg</p>");
	}

    public function sendproducts()
    {
        $this->log("Beginning sendProducts");
        
        $stores = Mage::app()->getStores();
        foreach ($stores as $store)
        {
            $name = $store->getName();
            $this->log("Exporting products for $name");
            
	        $store_id = $store->getId();
	        $this->log("Got store_id - $store_id");
	        
            # get access key
            $resource = Mage::getSingleton('core/resource');
            $this->log("Got Resource");
            
            $read = $resource->getConnection('core_read');
            $this->log("Got Read");
            
            $configDataTable = $read->getTableName('core_config_data');
            $this->log("Got config table");

            $accessToken = '';
            $select = $read->select()
                ->from(array('cd'=>$configDataTable))
                ->where('cd.scope=?', 'store')
                ->where("cd.scope_id=?", $store_id)
                ->where("cd.path=?", 'linc_access_key');
            $this->log("Got Select");
            
            $rows = $read->fetchAll($select);
            
            if (count($rows) > 0)
            {
                $accessToken = $rows[0]['value'];
            }

		    if ($accessToken != null)
		    {
		        $this->log("Got access key - $accessToken");
		        
			    $last_product = 0;
                $select = $read->select()
                    ->from(array('cd'=>$configDataTable))
                    ->where('cd.scope=?', 'store')
                    ->where("cd.scope_id=?", $store_id)
                    ->where("cd.path=?", 'linc_last_product_sent');
                $rows = $read->fetchAll($select);

                if (count($rows) > 0)
                {
                    $last_product = $rows[0]['value'];
                }
                
                $this->log("Starting with product # $last_product");
			
		        $products = Mage::getModel('catalog/product')->getCollection();
		        #$products->addAttributeToSelect("*");
		        $products->addStoreFilter($store_id);
                $products->addAttributeToFilter('entity_id', array('gt' => $last_product));
                $products->setOrder('entity_id', 'ASC');
                $products->getSelect()->limit(50);                              // limit number of results returned
                
                $this->log("Collection ready");
               
                foreach ($products as $product)
                {
                    $last_product = $product->getEntityId();
                    $type_id = $product->getTypeId();
                    
                    $this->log("Product ID - '$last_product'  Type ID - '$type_id'");
                    
                    if ($type_id == 'simple' || $type_id == 'downloadable')
                    {
                        $this->log('Sending product ' . $product->getName());
	                    $post_data_json = $this->buildJson($store, $product);
	                    
	                    if ($post_data_json != "")
	                    {
	                        $this->sendProduct($accessToken, $post_data_json);
	                    }
	                }
		        }
		
		        Mage::getConfig()->saveConfig('linc_last_product_sent', $last_product, 'store', $store_id);
                Mage::getConfig()->reinit();
                Mage::app()->reinitStores();
 		    }
		    else
		    {
		        $this->log('No access token');
		    }
		}
    }

	public function sendProduct($accessToken, $postData)
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
					sendProduct($accessToken, $data);
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
			
			if (in_array($temp, array(201)))
			{
				$temp = $response->getHeadersAsString();
				$this->log("Linc_Care Headers:/n$temp");
				$temp = $response->getBody();
				$this->log("Linc_Care Body:/n$temp");
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
		$this->client->setUri("$protocol://pub-api.$url/v1/product");
		
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
		
	public function buildJson($store, $product)
	{
	    $postdata = "";
		$this->log("buildJson started");
		
        $categoryCollection = $product->getCategoryCollection();
        $categories = $categoryCollection->exportToArray();
        $categsToLinks = array();
        $categoryList = '';
        # Get categories names
        foreach($categories as $category)
        {
            $categoryList .= Mage::getModel('catalog/category')->load($category['entity_id'])->getName() . ' ';
        }
        
        $manufacturer = '';
        if ($product->getAttributeText('manufacturer'))
        {
            $manufacturer = $product->getAttributeText('manufacturer');
        }

        $color = '';
        if ($product->getAttributeText('color'))
        {
            $color = $product->getAttributeText('color');
        }
        
        $qty = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product)->getQty();

        $dataitem = array(
            'brand'                 => $manufacturer,
            'manufacturer'          => $manufacturer,
            'model'                 => $product->getSku(),
            'ean'                   => $product->getEancode(),
            'upc'                   => '',
            'mpn'                   => $product->getInternalID(),
            'sku'                   => $product->getSku(),
            'title'                 => $product->getName(),
            'description'           => $product->getDescription(),
            'link'                  => $product->getProductUrl(),
            'thumbnail'             => Mage::getModel('catalog/product_media_config')->getMediaUrl( $product->getThumbnail() ),
            'images'                => Mage::getModel('catalog/product_media_config')->getMediaUrl( $product->getImage() ),
            'categories'            => $categoryList,
            'features'              => '',
            'country'               => $product->getAttributeText('country_of_manufacture'),
            'language'              => Mage::getStoreConfig('general/locale/code', $store->getId()),
            'color'                 => $color,
            'weight'                => $product->getWeight(),
            'length'                => '',
            'width'                 => '',
            'height'                => '',
            'currency'              => $store->getCurrentCurrencyCode(),
            'price'                 => $product->getPrice(),
            'inventory'             => $qty,
            'num_purchased'         => '',
            'updated'               => $product->getUpdatedAt(),
            'created'               => $product->getCreatedAt(),
            'deleted'               => 'false');
		
	    $postdata = json_encode($dataitem);
        $this->log("buildJson built an item $postdata");

	    $this->log("buildJson ended");
		
		return $postdata;
	}
}

?>
