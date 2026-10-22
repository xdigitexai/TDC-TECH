<?php
function tdc_rich_text(string $html):string{
 $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>',LIBXML_NONET|LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($previous);
 $allowed=['div','p','br','strong','b','em','i','u','ul','ol','li','h2','h3','blockquote'];
 $walk=function(DOMNode $node)use(&$walk,$allowed){foreach(iterator_to_array($node->childNodes) as $child){if($child instanceof DOMElement){$tag=strtolower($child->tagName);if(in_array($tag,['script','style','iframe','object','embed','svg','math','form','input','img','link','meta'],true)){$node->removeChild($child);continue;}$walk($child);if(!in_array($tag,$allowed,true)){while($child->firstChild)$node->insertBefore($child->firstChild,$child);$node->removeChild($child);}else foreach(iterator_to_array($child->attributes) as $attribute)$child->removeAttribute($attribute->name);}elseif($child instanceof DOMProcessingInstruction||$child instanceof DOMComment)$node->removeChild($child);}};
 $walk($doc);$out=$doc->saveHTML();return text_cut($out?:'',0,40000);
}
