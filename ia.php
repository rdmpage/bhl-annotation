<?php

// Core Internet Archive functions

error_reporting(E_ALL);

ini_set('memory_limit', '-1');

require (dirname(__FILE__) . '/iiif.php');

$config['cache']   = dirname(__FILE__) . '/cache';

//----------------------------------------------------------------------------------------
function head($url)
{
	$opts = array(
	  CURLOPT_URL =>$url,
	  CURLOPT_FOLLOWLOCATION => TRUE,
	  CURLOPT_RETURNTRANSFER => TRUE,
	  CURLOPT_HEADER		 => TRUE,
	  CURLOPT_NOBODY		 => TRUE
	);
	
	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch); 
	curl_close($ch);

	$http_code = $info['http_code'];
		
	return ($http_code == 200);
}

//----------------------------------------------------------------------------------------
function get($url, $format = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	
	if ($format != '')
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: " . $format));	
	}
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
	
	if ($http_code == 404)
	{
		$response = '';
	}
	
	//echo $http_code . "\n";
	
	curl_close($ch);
	
	return $response;
}

//----------------------------------------------------------------------------------------
function get_cache_directory($ia)
{
	global $config;
	
	$dir = $config['cache'] . '/' . $ia;

	if (!file_exists($dir))
	{
		$oldumask = umask(0); 
		mkdir($dir, 0777);
		umask($oldumask);
	}
	
	return $dir;	
}

//----------------------------------------------------------------------------------------
function clean_identifier($identifier)
{
	$identifier = str_replace(' ', '', $identifier);
	return $identifier;
}

//----------------------------------------------------------------------------------------
// Meta may have a different file name from the identifier
function get_meta_json($identifier)
{
	$json = '';
		
	$dir = get_cache_directory($identifier);	
	
	$filename = $dir. '/' . $identifier . '.json';
	
	if (!file_exists($filename))
	{
		$url = 'https://archive.org/metadata/' . clean_identifier($identifier);	
		$json = get($url);	
		file_put_contents($filename, $json);
	}
	
	$json = file_get_contents($filename);
	
	return $json;
}

//----------------------------------------------------------------------------------------
// Use IA API to get metadata
function get_metadata($identifier)
{
	$json = get_meta_json($identifier);	
	$obj = json_decode($json);	
	return $obj;
}

//----------------------------------------------------------------------------------------
function get_scandata($metadata)
{
	$xml = '';
	
	//print_r($metadata->files);
	
	$filename = '';
	foreach ($metadata->files as $file)
	{
		if ($file->format == 'Scandata')
		{
			$filename = $file->name;		
		}
	}
	
	if ($filename != '')
	{
		$dir = get_cache_directory($metadata->metadata->identifier);	
	
		$xml_filename = $dir. '/' . $filename;
	
		if (!file_exists($xml_filename))
		{
			$url = 'https://archive.org/download/' . clean_identifier($metadata->metadata->identifier) . '/' . rawurlencode($filename);
			$xml = get($url);	
			file_put_contents($xml_filename, $xml);
		}
	
		$xml = file_get_contents($xml_filename);
	}

	return $xml;

}

//----------------------------------------------------------------------------------------
function get_pages_scandata($xml) 
{
	$pages = array();

	$dom = new DOMDocument;
	$dom->loadXML($xml);
	$xpath = new DOMXPath($dom);
	
	// Sometimes (e.g., mobot31753002456132) some pages have zero width and height
	// in the XML, so we keep some default values handy for these cases
	$width 	= 1000;
	$height = 1000;
		
	$nodeCollection = $xpath->query ('/book/pageData/page');
	foreach($nodeCollection as $node)
	{
		$page = new stdclass;
		
		if ($node->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $node->attributes; 
			
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
			
			$page->leafNum = (Integer)$attributes['leafNum'];
		}
		
		// Get page dimensions
		if (0)
		{
			$nc = $xpath->query ('origWidth', $node);
			foreach($nc as $n)
			{
				$page->width = (Integer)$n->firstChild->nodeValue;
			
				if ($page->width == 0)
				{
					$page->width = $width;
				}
				else
				{
					$width = $page->width;
				}
			}

			$nc = $xpath->query ('origHeight', $node);
			foreach($nc as $n)
			{
				$page->height = (Integer) $n->firstChild->nodeValue;
			
				if ($page->height == 0)
				{
					$page->height = $height;
				}
				else
				{
					$height = $page->height;
				}			
			}
		}
		else
		{
			// Some scans are cropped, and these coordinates will be reflected in
			// OCR text cordinates, so use cropBox instead of 
			$nc = $xpath->query ('cropBox/w', $node);
			foreach($nc as $n)
			{
				$page->width = (Integer)$n->firstChild->nodeValue;
			
				if ($page->width == 0)
				{
					$page->width = $width;
				}
				else
				{
					$width = $page->width;
				}
			}

			$nc = $xpath->query ('cropBox/h', $node);
			foreach($nc as $n)
			{
				$page->height = (Integer) $n->firstChild->nodeValue;
			
				if ($page->height == 0)
				{
					$page->height = $height;
				}
				else
				{
					$height = $page->height;
				}			
			}
		}		
		
		$nc = $xpath->query ('pageNumber', $node);
		foreach($nc as $n)
		{
			$page->pageNumber = $n->firstChild->nodeValue;
		}
		
		// insert in order we encounter pages (can't rely on leafNum being correct)
		$pages[] = $page;
	
	}
	
	return $pages;	
}

//----------------------------------------------------------------------------------------
// Augment pages based on BHL METS
function get_bhl_pages($xml, $pages) 
{
	$dom = new DOMDocument;
	$dom->loadXML($xml);
	$xpath = new DOMXPath($dom);

	$xpath->registerNamespace("mets", "http://www.loc.gov/METS/");
	
	$nodeCollection = $xpath->query ('//mets:div[@TYPE="page"]');
	foreach($nodeCollection as $node)
	{
		if ($node->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $node->attributes; 
			
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
			
			$obj = new stdclass;
			
			if (isset($attributes['ORDER']))
			{
				$obj->order = $attributes['ORDER'];				
			}

			if (isset($attributes['LABEL']))
			{
				$obj->label = $attributes['LABEL'];	
			}	
						
			foreach($xpath->query('mets:fptr/@FILEID', $node) as $n)
			{
				$obj->bhl = $n->firstChild->nodeValue;
				$obj->bhl = preg_replace('/page(Img)?/', '', $obj->bhl);
			}
			
			// assume order is 1-offset
			if (isset($obj->order))
			{			
				$sequence = $obj->order - 1;
				
				if (isset($obj->label))
				{			
					$pages[$sequence]->pageLabel = $obj->label;
					
					// if no page numbering from scan data try and use BHL
					if (!isset($pages[$sequence]->pageNumber))
					{
						if (preg_match('/p\.\s+(?<number>\d+)/', $pages[$sequence]->pageLabel, $m))
						{
							$pages[$sequence]->pageNumber = $m['page'];
						}
					}
				}
				
				if (isset($obj->bhl))
				{			
					$pages[$sequence]->bhl = $obj->bhl;
				}								
			}			
		}
	}
	
	return $pages;
}

//----------------------------------------------------------------------------------------
function get_bhl_mets($metadata)
{
	$xml = '';
	
	$filename = '';
	foreach ($metadata->files as $file)
	{
		if ($file->format == 'Biodiversity Heritage Library METS')
		{
			$filename = $file->name;		
		}
	}
	
	if ($filename != '')
	{
		$dir = get_cache_directory($metadata->metadata->identifier);	
	
		$xml_filename = $dir. '/' . $filename;
	
		if (!file_exists($xml_filename))
		{
			$url = 'https://archive.org/download/' . clean_identifier($metadata->metadata->identifier) . '/' . rawurlencode($filename);
			$xml = get($url);	
			file_put_contents($xml_filename, $xml);
		}
	
		$xml = file_get_contents($xml_filename);
	}

	return $xml;
}

//----------------------------------------------------------------------------------------
function extract_box($text)
{
	$bbox = array();
	
	if (preg_match('/bbox (\d+) (\d+) (\d+) (\d+)/', $text, $m))
	{
		$bbox = array(
			$m[1], 
			$m[2],
			$m[3],
			$m[4]
			);
	}

	return $bbox;
}

//----------------------------------------------------------------------------------------
function hocr_to_tokens($html)
{
	$pages = array();
	
	$dom = new DOMDocument;
	$dom->loadXML($html);
	$xpath = new DOMXPath($dom);

	$xpath->registerNamespace("xhtml", "http://www.w3.org/1999/xhtml");

	$sequence = 0;
	
	foreach ($xpath->query('//xhtml:div[@class="ocr_page"]') as $ocr_page)
	{
	
		$page = new stdclass;	
		
		// coordinates and other attributes 
		if ($ocr_page->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $ocr_page->attributes; 
		
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
		}
		$bbox = extract_box($attributes['title']);
		$page->width = (Integer)$bbox[2] - (Integer)$bbox[0];
		$page->height= (Integer)$bbox[3] - (Integer)$bbox[1];
				
		$page->tokens = array();		
		$page->words = array();
		$page->pos_to_token = array();
		
		$areas = array();
		foreach ($xpath->query('xhtml:div[@class="ocr_carea"]', $ocr_page) as $ocr_carea)
		{		
			foreach ($xpath->query('xhtml:p[@class="ocr_par"]', $ocr_carea) as $ocr_par)
			{
				foreach ($xpath->query('xhtml:span[@class="ocr_line"]', $ocr_par) as $ocr_line)
				{
					foreach ($xpath->query('xhtml:span[@class="ocrx_word"]', $ocr_line) as $ocrx_word)
					{					
						if ($ocrx_word->hasAttributes()) 
						{ 
							$attributes = array();
							$attrs = $ocrx_word->attributes; 
		
							foreach ($attrs as $i => $attr)
							{
								$attributes[$attr->name] = $attr->value; 
							}
						}
				
						$bbox = extract_box($attributes['title']);
										
						$token = new stdclass;			
						$token->x = (Integer)$bbox[0];
						$token->y = (Integer)$bbox[1];
						$token->w = (Integer)$bbox[2] - (Integer)$bbox[0];
						$token->h = (Integer)$bbox[3] - (Integer)$bbox[1];	
		
						$token->text = $ocrx_word->firstChild->textContent;
		
						$page->tokens[] = $token;			
						$page->words[] = $token->text;
		
						$length = mb_strlen($token->text, mb_detect_encoding($token->text)) + 1;
		
						$index = count($page->tokens) - 1;
		
						for ($i = 0; $i < $length; $i++)
						{
							$page->pos_to_token[] = $index;
						}
					}
				}
			}
			
		}
		$page->text = join(' ', $page->words);
		unset($page->words);	
		$pages[] = $page;
		
	}

	return $pages;
}

//----------------------------------------------------------------------------------------
// Get basic page info (size, tokens, text) from DjVu XML
function djvu_to_tokens($xml)
{			
	$pages = array();
	
	$dom = new DOMDocument;
	$dom->loadXML($xml);
	$xpath = new DOMXPath($dom);
					
	foreach($xpath->query ('//OBJECT') as $xml_page)
	{
		$page = new stdclass;	
		
		// page coordinates
		if ($xml_page->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $xml_page->attributes; 
		
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
		}
	
		$page->width = (Integer)$attributes['width'];
		$page->height = (Integer)$attributes['height'];	
				
		$page->tokens = array();		
		$page->words = array();
		$page->pos_to_token = array();
		
		foreach ($xpath->query ('HIDDENTEXT/PAGECOLUMN/REGION', $xml_page) as $region)
		{
			foreach ($xpath->query ('PARAGRAPH', $region) as $paragraph)
			{
				foreach ($xpath->query ('LINE', $paragraph) as $line)
				{
					foreach ($xpath->query('WORD', $line) as $word)
					{
						// attributes
						if ($word->hasAttributes()) 
						{ 
							$attributes = array();
							$attrs = $word->attributes; 

							foreach ($attrs as $i => $attr)
							{
								$attributes[$attr->name] = $attr->value; 
							}
						}

						$coords = explode(",", $attributes['coords']);
				
						$token = new stdclass;			
						$token->x = (Integer)$coords[0];
						$token->y = (Integer)$coords[3];
						$token->w = (Integer)$coords[2] - (Integer)$coords[0];
						$token->h = (Integer)$coords[1] - (Integer)$coords[3];	
		
						$token->text = $word->firstChild->nodeValue;
		
						$page->tokens[] = $token;			
						$page->words[] = $token->text;
		
						$length = mb_strlen($token->text, mb_detect_encoding($token->text)) + 1;
		
						$index = count($page->tokens) - 1;
		
						for ($i = 0; $i < $length; $i++)
						{
							$page->pos_to_token[] = $index;
						}
					}

				}	
			}
		}
		
		$page->text = join(' ', $page->words);
		unset($page->words);	
		$pages[] = $page;
	}
	
	return $pages;
}

//----------------------------------------------------------------------------------------
// get page text, store on disk as JSON file. We expect to only use this for text mining,
// not display (?)
function get_text($metadata, $force = false)
{
	// have we done this already?
	$dir = get_cache_directory($metadata->metadata->identifier);	

	$page_filename = $dir. '/page-0.json';

	// ok?
	if (file_exists($page_filename) && !$force)
	{
		return;
	}

	// nope, we need to do this
	
	echo "Need to get...\n";

	$pages = array();
	
	$xml = '';
	$format = '';
	
	// Use either Djvu XML, hOCR
	
	// Try for hOCR
	$filename = '';
	foreach ($metadata->files as $file)
	{
		if ($file->format == 'hOCR')
		{
			$filename = $file->name;
			$format = $file->format;
		}
	}
	
	// No? OK, DjVu it is
	if ($filename == '')
	{
		foreach ($metadata->files as $file)
		{
			if ($file->format == 'Djvu XML')
			{
				$filename = $file->name;
				$format = $file->format;
			}
		}
	}
	
	echo "format=" . $filename . "\n";
	echo "format=" . $format . "\n";
	
	// we should now have a source for text with coordinates
	
	switch ($format)
	{
		case 'Djvu XML':
			$dir = get_cache_directory($metadata->metadata->identifier);
			$text_filename = $dir. '/' . $filename;
			
			if (!file_exists($text_filename))
			{
				$url = 'https://archive.org/download/' . clean_identifier($metadata->metadata->identifier) . '/' . rawurlencode($filename);
				$xml = get($url);	
				file_put_contents($text_filename, $xml);
			}
			
			$xml = file_get_contents($text_filename);			
			$pages = djvu_to_tokens($xml);					
			break;
			
		case 'hOCR': // to do!!!!!
			$dir = get_cache_directory($metadata->metadata->identifier);
			$text_filename = $dir. '/' . $filename;
			
			if (!file_exists($text_filename))
			{
				$url = 'https://archive.org/download/' . clean_identifier($metadata->metadata->identifier) . '/' . rawurlencode($filename);
				$html = get($url);	
				file_put_contents($text_filename, $html);
			}	
			
			$html = file_get_contents($text_filename);
			$pages = hocr_to_tokens($html);
			break;
			
		default:
			break;
	}
	
	$page_number = 0;
	foreach ($pages as $page)
	{
		$page_filename = $dir . '/page-' . $page_number . '.json';
		file_put_contents($page_filename, json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$page_number++;
	}
	
}

//----------------------------------------------------------------------------------------
// Get given page object from cache
function get_one_page($ia, $page_number = 0)
{			
	$metadata = get_metadata($ia);

	$dir = get_cache_directory($metadata->metadata->identifier);	

	$page_filename = $dir. '/page-' . $page_number . '.json';
	
	$json = file_get_contents($page_filename);
	$page = json_decode($json);
	
	return $page;
}

//----------------------------------------------------------------------------------------
function create_manifest($ia, $force = false)
{
	$google_width = 685;
	$thumbnail_width = 100;
	
	$manifest_filename = $ia . '.json';
	
	if (!file_exists($manifest_filename) || $force)
	{	
		// Get IA metadata
		$metadata = get_metadata($ia);

		// Get details about the pages (size, labels, BHL ids)
		$xml = get_scandata($metadata);
		$pages = get_pages_scandata($xml);
		
		// Do we have IA page numbers?
		if (isset($metadata->page_numbers))
		{
			foreach ($metadata->page_numbers->pages as $page)
			{
				if ($page->confidence == 100)
				{
					$sequence = $page->leafNum;
					$pages[$sequence]->pageLabel = $page->pageNumber;					
				}
			}
		}

		// Do we have any BHL-specific info?
		$bhl_page_ids = array();
		$xml = get_bhl_mets($metadata);	
		if ($xml != '')
		{
			$pages = get_bhl_pages($xml, $pages);
		
			// store a mapping from BHL PageID to page
			foreach ($pages as $index => $page)
			{
				if (isset($page->bhl))
				{
					$bhl_page_ids[$page->bhl] = $index;
				}			
			}
			
		}
	
		// get the text and store for annotations, etc.
		get_text($metadata);
	
	
		// Create a IIIF manifest
		$id = 'https://archive.org/details/' . $metadata->metadata->identifier;
	
		$iiif = new IIIF($id);
	
		// title
		$terms = array();
		if (isset($metadata->metadata->title))
		{
			$terms[] = $metadata->metadata->title;
		}
		if (isset($metadata->metadata->volume))
		{
			$terms[] = $metadata->metadata->volume;
		}
		if (isset($metadata->metadata->year))
		{
			$terms[] = $metadata->metadata->year;
		}		
		$iiif->set_title(join(" ", $terms));
	
		// identifiers
	
		// provider(s) so we have logos	
		if (count($bhl_page_ids) > 0)
		{
			// BHL is a provider
			$iiif->add_provider("BHL"); 		
		}		
		
		if (isset($metadata->metadata->contributor))
		{
			$iiif->add_provider($metadata->metadata->contributor);
		}		
	
		// add pages
		foreach ($pages as $page_order => $page)
		{
			$pageLabel = "";
			if (isset($page->pageLabel))
			{
				$pageLabel = $page->pageLabel;
			}
			
			$aspect_ratio = $page->width / $page->height;
			
			// related things			
			$seeAlso = array();
			
			// BHL page id
			if (isset($page->bhl))
			{
				$identifier = new stdclass;
				$identifier->id = "https://www.biodiversitylibrary.org/page/" . $page->bhl;
				$identifier->type = "CreativeWork";
				$identifier->format = "text/html";
				$identifier->label = new stdclass;
				$identifier->label->en = "BHL Page " . $page->bhl;
				
				$seeAlso[] = $identifier;
			}
		
			$canvas = $iiif->add_page(
				$pageLabel,
				$page->width,
				$page->height,
				'https://archive.org/download/' . $metadata->metadata->identifier . '/page/n' . $page_order . '_w' . $google_width . '.jpg',
				"image/jpg",
				$thumbnail_width,
				($thumbnail_width / $aspect_ratio), 
				'https://archive.org/download/' . $metadata->metadata->identifier . '/page/n' . $page_order . '_w' . $thumbnail_width . '.jpg',
				"image/jpg",
				$seeAlso
				);
				
	
		}
	
		file_put_contents($manifest_filename, json_encode($iiif->manifest,  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}
}

//----------------------------------------------------------------------------------------
function get_manifest($ia, $force = false)
{
	$manifest_filename = $ia . '.json';
	
	if (!file_exists($manifest_filename) || $force)
	{
		create_manifest($ia, $force);
	}
	
	$json = file_get_contents($manifest_filename);
	
	$manifest = json_decode($json);
	
	return $manifest;
}


?>
