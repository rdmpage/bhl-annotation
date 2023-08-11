<?php

// Simple class to generate a IIIF manifest (kinda)

//----------------------------------------------------------------------------------------
// Base class
class IIIF
{
	var $manifest;
	var $base_uri = '';
	var $page_labels = array();
	var $num_pages = 0;

	//------------------------------------------------------------------------------------
	function __construct($base_uri = 'http://example.com')
	{
		$this->base_uri = $base_uri;		
		$this->create_manifest();
	}
	
	//------------------------------------------------------------------------------------
	function init($parameters)
	{
		
	}
	
	//------------------------------------------------------------------------------------
	function create_manifest()
	{	
		$this->manifest = new stdclass;
		$this->manifest->{'@context'} = "http://iiif.io/api/presentation/3/context.json";

		$this->manifest->id = $this->base_uri . '/manifest.json';
		
		$this->manifest->type = "Manifest";
		
		// default title
		$this->set_title();
		
		// provider(s)
		$this->manifest->provider = array(); 
			
		// paged content
		$this->manifest->behaviour = array("paged");
		
		// pages (canvases)
		$this->manifest->items = array();	
	}
	
	//------------------------------------------------------------------------------------
	function add_doi($identifier)
	{
		$doi = new stdclass;
		$doi->id = 'https://doi.org/' . strtolower($identifier);
		$doi->type = 'CreativeWork';
		$doi->format = 'text/html';

		$this->add_seeAlso($doi);
	}	

	//------------------------------------------------------------------------------------
	function add_provider($name)
	{		
		if (!isset($this->manifest->provider))
		{
			$this->manifest->provider = array();
		}
		
		$provider = new stdclass;
		
		switch ($name)
		{
			case 'BHL':
				$provider->id = 'https://www.biodiversitylibrary.org';
				$provider->type = "Agent";
				$provider->label = new stdclass;
				$provider->label->en = 'Biodiversity Heritage Library';
							
				$logo = new stdclass;
				$logo->id = 'http://localhost/bhl-annotations-o/logos/twitter_BHL_Small_Logo_400x400.jpg';
				$logo->type = "image";
				$logo->format = "image/jpeg";
				$logo->width = 400;
				$logo->height = 400;
				
				$provider->logo[] = $logo;
				break;

			case 'Missouri Botanical Garden':
				$provider->id = 'https://www.missouribotanicalgarden.org';
				$provider->type = "Agent";
				$provider->label = new stdclass;
				$provider->label->en = 'Missouri Botanical Garden';
							
				$logo = new stdclass;
				$logo->id = 'http://localhost/bhl-annotations-o/logos/twitter_UUvmzV5e_400x400.jpg';
				$logo->type = "image";
				$logo->format = "image/jpeg";
				$logo->width = 400;
				$logo->height = 400;
				
				$provider->logo[] = $logo;
				break;

			case 'Phytologia':
				$provider->id = 'https://www.phytologia.org';
				$provider->type = "Agent";
				$provider->label = new stdclass;
				$provider->label->en = 'Phytologia';
				break;
				
			default:
				$provider = null;
				break;		
		}
		
		if ($provider)
		{
			$this->manifest->provider[] = $provider;	
		}
	}	
	
	//------------------------------------------------------------------------------------
	function add_seeAlso($identifier)
	{		
		if (!isset($this->manifest->seeAlso))
		{
			$this->manifest->seeAlso = array();
		}
		$this->manifest->seeAlso[] = $identifier;	
	}		
	
	//------------------------------------------------------------------------------------
	function add_page($page_label = "", 
		$image_width=100, 
		$image_height=100, 
		$image_url="http://example.com/image.png", 
		$image_format ="image/png",
		$thumbnail_width=100, 
		$thumbnail_height=100,		
		$thumbnail_url = "", 
		$thumbnail_format ="image/png",
		$seeAlso = array()
		)
	{
		$this->num_pages++;
		
		// A canvas is where we paint the page
		$canvas = new stdclass;
		$canvas->id = $this->base_uri . '/canvas/p' . $this->num_pages;
		$canvas->type = "Canvas";
		
		// Do we have any external identifiers?
		if (count($seeAlso) > 0)
		{
			$canvas->seeAlso = array();		
			foreach ($seeAlso as $identifier)
			{
				$canvas->seeAlso[] = $identifier;
			}
		}
	
		// Page label (may be simple sequential numbers or something more complex)
		$canvas->label = new stdclass;
		$canvas->label->none = array();			
		if ($page_label == '')
		{
			$page_label = "[" . $this->num_pages . "]";
		}		
		$canvas->label->none[] = $page_label;	
	
		// Canvas dimension to match image
		$canvas->width  = $image_width;
		$canvas->height = $image_height;
		
		// Do we have a thumbnail?
		if ($thumbnail_url != "")
		{
			$thumbnail = new stdclass;
			$thumbnail->id = $thumbnail_url;
			$thumbnail->type = "Image";
			$thumbnail->format = $thumbnail_format;
			$thumbnail->width  = $thumbnail_width;
			$thumbnail->height = $thumbnail_height;

			$canvas->thumbnail[] = $thumbnail;
		}
	
		// Paint the canvas
		$canvas->items = array();

		$item = new stdclass;
		$item->id = $canvas->id . '/annopage-1';
		$item->type = "AnnotationPage";
		$item->items = array();
	
		// page image is an annotation
		$annotation = new stdclass;
		$annotation->id = $item->id . '/anno-1';
		$annotation->type = "Annotation";
		$annotation->motivation = "painting";

		$annotation->body = new stdclass;
		$annotation->body->id = $image_url; // URI for image
		$annotation->body->type = "Image";
	
		$annotation->body->width  = $image_width;
		$annotation->body->height = $image_height;	
		$annotation->body->format = $image_format;
		$annotation->target = $canvas->id;	
	
		$item->items[] = $annotation;
	
		$canvas->items[] = $item;
			
		$this->manifest->items[] = $canvas;	
	}
	
	//------------------------------------------------------------------------------------
	// to do:
	function add_painting_annotation($item_number = 0, $annotation)
	{
		$annotation_count = count($this->manifest->items[$item_number]->items);
	}
	
	//------------------------------------------------------------------------------------
	// to do:
	function add_nonpainting_annotation($item_number = 0, $annotation)
	{
		if (!isset($this->manifest->items[$item_number]->annotations))
		{
			$this->manifest->items[$item_number]->annotations = array();
		}
		$annotation_count = count($this->manifest->items[$item_number]->annotations);
		
		// to do
	}
	
	//------------------------------------------------------------------------------------
	function set_title($title = "Untitled")
	{
		// title
		$this->manifest->label = new stdclass;
		$this->manifest->label->en = array($title);	
	}
	
	//------------------------------------------------------------------------------------
	// get mapping between pages and external identifiers (e.g., BHL PageIDs)
	
	
	//to do!!!!!!!
	
	
	function get_bhl_to_canvas()
	{
		$bhl = array();
		
		foreach ($this->items as $index => $canvas)
		{
			if (isset($canvas->seeAlso))
			{
				//echo 'data-seealso="' . rawurlencode(json_encode($canvas->seeAlso)) . '" ';
			}	
		}
	
	}

}

?>
