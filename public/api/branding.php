<?php
require dirname(__DIR__,2).'/app/bootstrap.php';header('Cache-Control: no-store');$rows=db()->query("SELECT setting_key,setting_value,updated_at FROM site_settings WHERE setting_key IN ('logo_url','favicon_url','brand_name')")->fetchAll();$out=[];foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value'];json_out($out);
