<?php
require_once "Linc/Recommends/common.php";

class Linc_Recommends_Block_Recommends  extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $html = "";

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $configDataTable = $read->getTableName('core_config_data');

        # get store_id
        $store_id = '1';
        $select = $read->select()
            ->from(array('cd'=>$configDataTable))
            ->where("cd.path=?", 'linc_current_store');
        $rows = $read->fetchAll($select);

        if (count($rows) > 0)
        {
            $store_id = $rows[0]['value'];
        }

        # get shop_id
        $shop_id = '';
        $select = $read->select()
            ->from(array('cd'=>$configDataTable))
            ->where('cd.scope=?', 'store')
            ->where("cd.scope_id=?", $store_id)
            ->where("cd.path=?", 'linc_shop_id');
        $rows = $read->fetchAll($select);

        if (count($rows) > 0)
        {
            $shop_id = $rows[0]['value'];
        }

        if ($shop_id != null)
        {
            $html = "<table border=0 cellspacing=0 cellpadding=20><tr>";
            $order = $this->getOrder();
            $items = $order->getItemsCollection();

            $query = "";
            $baseurl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA).'catalog/product';
            foreach ($items as $item)
            {
                $product = Mage::getModel('catalog/product')->load($item->getProduct()->getId());
                if ($product->isVisibleInSiteVisibility())
                {
                    $imgurl = $baseurl.$product->getImage();

                    if ($query != "")
                    {
                        $query .= "&";
                    }

                    $query .= "q=".$item->getQtyOrdered();
                    $query .= "&p=".$product->getId();
                    $query .= "&pp=".$item->getPrice();
                    $query .= "&w=".$item->getWeight();
                    $query .= "&i=".$imgurl;
                    $query .= "&n=".$item->getName();
                }
            }

            $s_addr = $order->getShippingAddress();
            if ($s_addr == null)
            {
                /* use billing address for shipping address when the purchase is a download. */
                $s_addr = $order->getBillingAddress();
            }

            $query .= "&a1=".$this->urlencode($s_addr->getStreet1());
            $query .= "&a2=".$this->urlencode($s_addr->getStreet2());
            $query .= "&au=".$this->urlencode($s_addr->getCountry());
            $query .= "&ac=".$this->urlencode($s_addr->getCity());
            $query .= "&as=".$this->urlencode($s_addr->getRegion());
            $query .= "&az=".$this->urlencode($s_addr->getPostcode());
            $query .= "&fn=".$this->urlencode($order->getCustomerFirstname());
            $query .= "&ln=".$this->urlencode($order->getCustomerLastname());
            $query .= "&e=".$this->urlencode($order->getCustomerEmail());
            $query .= "&g=".$order->getGrandTotal();
            $query .= "&o=".$order->getIncrementId();
            $query .= "&osi=".$order->getIncrementId();
            $query .= "&pd=".$this->urlencode($order->getUpdatedAt('long'));
            $query .= "&ph=".$this->urlencode($s_addr->getTelephone());
            $query .= "&shop_id=".$shop_id;
            $query .= "&source=email";
            $query .= "&v=2";


            for ($i = 1; $i < 4; $i++)
            {
                $html .= "<td><a id='prod-rec".$i."' href='https://care.letslinc.com/product_rec?";
                $html .= $query."&pos=1&field=link'><img src='https://care.letslinc.com/product_rec?";
                $html .= $query."&pos=".$i."' width='150'></a></td>";
            }

            $html .= "</tr></table>";

            //Mage::log("Recommendations complete - $html", null, 'order.log', true);
        }
        else
        {
            $html = "<p></p>";
        }

        return $html;
    }
}

?>
