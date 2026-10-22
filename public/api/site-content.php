<?php
require_once dirname(__DIR__,2).'/app/bootstrap.php';
require_method('GET');
$settings=[];$q=db()->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('meta_title','meta_description','brand_name','logo_url','favicon_url','home_title','home_subtitle','ads_hashtags','footer_pricing_text','footer_partnerships')");
foreach($q->fetchAll() as $row)$settings[$row['setting_key']]=$row['setting_value'];
$items=db()->query("SELECT id,item_type,title,subtitle,body,image_url,icon_text,href,rating,sort_order FROM site_content_items WHERE active=1 ORDER BY item_type,sort_order,id")->fetchAll();
$grouped=[];foreach($items as $item)$grouped[$item['item_type']][]=$item;
$pages=db()->query('SELECT slug,name,description,icon,active FROM service_pages ORDER BY sort_order')->fetchAll();
$services=db()->query('SELECT slug,name,price,pricing_mode,dashboard_icon FROM services WHERE active=1 ORDER BY id')->fetchAll();
$pageItems=db()->query('SELECT ps.page_slug,s.slug,s.name,s.price,s.pricing_mode,s.active FROM service_page_services ps JOIN services s ON s.id=ps.service_id ORDER BY s.id')->fetchAll();$mapped=[];foreach($pageItems as $item)$mapped[$item['page_slug']][]=$item;
json_out(['settings'=>$settings,'items'=>$grouped,'service_pages'=>$pages,'services'=>$services,'page_services'=>$mapped]);
