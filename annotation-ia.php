
<?php

// Experiment with annotations

// need IA identifier and a zero-offset page number, we fetch text, run name finder, 
// create annotation, and output it as an array

error_reporting(E_ALL);

require (dirname(__FILE__) . '/ia.php');
require (dirname(__FILE__) . '/lib/textsearch.php');

//----------------------------------------------------------------------------------------
// Given a hit convert to a bounding box on the page and return corresponding 
// fragment identifier
function hit_to_fragment(
	$page, 	// structure holding page text and tokens
	$hit	// details of hit
	)
{
	// get list of tokens that the hit spans
	$token_list = array();
	for ($i = $hit->range[0]; $i <= $hit->range[1]; $i++)
	{
		$token_list[] = $page->pos_to_token[$i];
	}			
	$token_list = array_unique($token_list);

	// get bounding box for these tokens on the page			
	$x0 = $page->width;
	$y0 = $page->height;
	$x1 = 0;
	$y1 = 0;
		
	foreach ($token_list as $i)
	{
		$token = $page->tokens[$i];
	
		$x0 = min($x0, $token->x);
		$y0 = min($y0, $token->y);

		$x1 = max($x1, $token->x + $token->w);
		$y1 = max($y1, $token->y + $token->h);				
	}

	// fragment identifier 
	$fragment = 'xywh=' . round($x0) . ',' . round($y0) .  ',' .  round($x1 - $x0) . ',' .  round($y1 - $y0);

	return $fragment;
}

/*
//----------------------------------------------------------------------------------------
// Create annotation on a canvas
function create_annotation (
	$canvas, // IIIF canvas for the page we are annotating
	$page, 
	$hit)
{
	global $cuid;
	
	$fragment = hit_to_fragment($page, $hit);

	// make annotation for painting
	$annotation = new stdclass;						
	$annotation->id = $canvas->id . '/annotation/' . $cuid->slug();						
	$annotation->type = "Annotation";
	
	$annotation->motivation = array("painting", "tagging");
			
	$body = new stdclass;
	$body->type 	= "TextualBody";
	$body->format 	= "text/plain";
	$body->language = "none";
	$body->value 	= $hit->exact;
	
	if (isset($hit->id))
	{
		$annotation->body = array();
		$annotation->body[] = $body;
	
		$body = new stdclass;
		$body->type = "SpecificResource";
		$body->source = $hit->id;
		
		$annotation->motivation[] = "linking";

		$annotation->body[] = $body;
	}
	else
	{
		$annotation->body = $body;
	}
	
	$annotation->target = new stdclass;
	$annotation->target->type = 'SpecificResource';
	$annotation->target->source = $canvas->id;
	
	$annotation->target->selector = array();

	// fragment selector
	$selector = new stdclass;
	$selector->type 	= 'FragmentSelector';
	$selector->value 	= $fragment;	
	$annotation->target->selector[] = $selector;	

	// quote selector
	$selector = new stdclass;
	$selector->type 	= 'TextQuoteSelector';
	$selector->prefix 	= $hit->prefix;
	$selector->exact 	= $hit->exact;
	$selector->suffix 	= $hit->suffix;	
	$annotation->target->selector[] = $selector;

	// pos selector
	$selector = new stdclass;
	$selector->type 	= 'TextPositionSelector';
	$selector->start 	= $hit->range[0];
	$selector->end 		= $hit->range[1];
	$annotation->target->selector[] = $selector;
	
	return $annotation;
}
*/

/*
//----------------------------------------------------------------------------------------
function search_names_on_page($ia, &$manifest, $zerobased_page_number, $verify = false)
{
	$annotations = array();
	
	// Get canvas we will be annotating
	$canvas = $manifest->items[$zerobased_page_number];

	// Get text and tokens for this page
	$page = get_one_page($ia, $zerobased_page_number);
	
	echo $page->text;

	// create a temporary file for the text on the page
	$text_filename = tempnam(sys_get_temp_dir(), 'text_');

	file_put_contents($text_filename, $page->text);

	// detailed annotation using gnfinder	
	$options = array(
		'--utf8-input', 
		'--words-around 6', 	// number of words round name
		' --format pretty', 	// make JSON output look pretty		
	);
	
	if ($verify)
	{
		// look up names in external databases
		$options[] = '--verify'; 
		
		// comma-delimited list of sources to check against 1=CoL, 168=ION, 5=IF, 167=IPNI	
		//$options[] = '--sources 168'; // ION
		$options[] = '--sources 5';	// IF
	}
	
	$command = 'gnfinder ' . join(' ', $options) . ' ' . $text_filename;

	$json = shell_exec($command);

	echo $json;

	$response = json_decode($json);

	foreach ($response->names as $name)
	{
		// filter?
		$ok = false;
		
		// filter out weak evidence for name
		if ($name->oddsLog10 > 8)
		{
			$ok = $ok || true;
		}
			
		// only names with an annotation	
		if (isset($name->annotationNomenType) && $name->annotationNomenType != "NO_ANNOT")
		{
			$ok = true;
		}	
		else
		{
			$ok = false;
		}			
		
		if ($ok)
		{
			$hit = new stdclass;
			
			// text
			$hit->body = $name->verbatim;
	
			// location in text
			$hit->range = array($name->start, $name->end);
	
			// context
			$hit->exact = $name->verbatim;			
			if (isset($name->wordsBefore))
			{
				$hit->prefix = join(' ', $name->wordsBefore);
			}
			else
			{
				$hit->prefix = '';
			}
			if (isset($name->wordsAfter))
			{
				$hit->suffix = join(' ', $name->wordsAfter);
			}
			else
			{
				$hit->suffix = '';
			}
			
			// tag with GUID for name
			if (isset($name->verification))
			{
				if (isset($name->verification->bestResult))
				{
					switch ($name->verification->bestResult->dataSourceId)
					{
						case 1:
							break;

						case 5:
							$hit->id = 'urn:lsid:indexfungorum.org:names:' . $name->verification->bestResult->recordId;
							break;

						case 167:
							break;
							
						case 168:
							$hit->id = 'urn:lsid:organismnames.com:name:' . $name->verification->bestResult->recordId;
							break;
					
						default:
							break;
					}
				}
			}
			
			$annotation = create_annotation($canvas, $page, $hit);
			
			$annotations[] = $annotation;
			
			if (0)
			{
				// source of name
				$annotation->generator = new stdclass;
				$annotation->generator->id = "https://doi.org/10.5281/zenodo.7278416";
				$annotation->generator->type = "Software";
				$annotation->generator->name = "gnfinder v1.0.4+";	
			}					
		}
		
	}
	
	return $annotations;
}
*/

//----------------------------------------------------------------------------------------


// Find strings within text of a given page
function find_on_page($ia, &$manifest, $zerobased_page_number, $targets = array(), $maxerror = 1)
{
	$hits = array();

	// Get canvas we will be annotating
	$canvas = $manifest->items[$zerobased_page_number];
	
	
	// Get text and tokens for this page
	$page = get_one_page($ia, $zerobased_page_number);
	
	foreach ($targets as $target)
	{
		$ignorecase = true;
		$maxerror 	= 1;
	
		// find target text on page
		$results = find_in_text(
			$target, 
			$page->text, 
			$ignorecase,
			$maxerror
			);
			
		// create annotation(s) for hits
		foreach ($results->selector as $result)
		{
			$hit = new stdclass;
			
			$hit->body = $target;
	
			$hit->range = $result->range;
	
			$hit->exact = $result->exact;			
			$hit->prefix = $result->prefix;
			$hit->suffix = $result->suffix;
			
			$hit->fragment = hit_to_fragment($page, $hit);
			
			$hit->width = $canvas->width;
			$hit->height = $canvas->height;
			
			$hits[] = $hit;
		}		
	
	}
	
	return $hits;
}


//----------------------------------------------------------------------------------------

// how does this relate to BHL item ids?

$ia = 'austrobaileya1quee';
	
// Get manifest
$manifest = get_manifest($ia, true);

//  Make sure we have text and token information for this IA item
$metadata = get_metadata($ia);
get_text($metadata);

/*
// Case 1: find names in text using a name finding tool (we don't know what names occur there)
if (1)
{
	$verify = true;
	$verify = false;
	
	$annotation_list = array();

	$n = count($manifest->items);
	//for ($i = 0; $i < $n; $i++)
	for ($i = $zerobased_page_number; $i <= $zerobased_page_number; $i++)
	{
		$annotations = find_names_on_page($ia, $manifest, $i, $verify);
		
		foreach ($annotations as $annotation)
		{
			if (!isset($annotation_list[$annotation->target->source]))
			{
				$annotation_list[$annotation->target->source] = array();
			}
			$annotation_list[$annotation->target->source][] = $annotation;
		}
	}
	
	
	print_r($annotation_list);
	
	echo json_encode($annotation_list);


}
*/


// Case 2: : given name we believe occurs on page, find it.
if (1)
{
	$pages = array();
	
	$pages_names = array(
		6 => array('Eucalyptus brassiana')
	);
	$pages_names = array(
1  => ['Eucalyptus brassiana'],
11 => ['Blechnum articulatum'],
12 => ['Crypsinus simplicissimus','Ctenopteris fuscopilosa','Ctenopteris gordonii','Ctenopteris maidenii','Ctenopteris walleri','Microsorum superficiale var. australiense','Oenotrichia dissecta','Pteridium semihastatum'],
13 => ['Solanum callium'],
24 => ['Polyscias bellendenkerensis','Polyscias willmottii'],
28 => ['Acacia bivenosa subsp. wayi'],
29 => ['Acacia mimula'],
33 => ['Caesalpinia subtropica','Lysiphyllum carronii', 'Lysiphyllum gilvum','Lysiphyllum hookeri'],
34 => ['Caesalpinia robusta','Daviesia discolor'],
35 => ['Daviesia flava'],
36 => ['Mirbelia confertiflora'],
37 => ['Mirbelia speciosa subsp. ringrosei'],
38 => ['Tephrosia rufula'],
38 => ['Tephrosia spechtii'],
39 => ['Tephrosia benthamii','Tephrosia delestangii'],
4 => ['Eucalyptus henryi'],
40 => ['Tephrosia virens'],
43 => ['Allosyncarpia'],
44 => ['Allosyncarpia ternata'],
47 => ['Verticordia decussata'],
48 => ['Verticordia verticillata'],
51 => ['Polycarpaea fallax'],
52 => ['Polycarpaea corymbosa var. minor'],
53 => ['Polycarpaea corymbosa var. torrensis'],
54 => ['Polycarpaea arida'],
55 => ['Polycarpaea microphylla'],
58 => ['Polycarpaea spirostylis subsp. glabra'],
59 => ['Polycarpaea spirostylis subsp. compacta','Polycarpaea spirostylis subsp. densiflora'],
6 => ['Eucalyptus melanoleuca'],
60 => ['Polycarpaea breviflora var. gracilis'],
7 => ['Eucalyptus urophylla'],
70 => ['Dendrobium tozerensis'],
72 => ['Oberonia carnosa']	
);
	
	foreach ($pages_names as $zerobased_page_number => $targets)
	{
		$zerobased_page_number += 5; // custom offset
		$pages[$zerobased_page_number] = find_on_page($ia, $manifest, $zerobased_page_number, $targets);	
	}
	
	
	print_r($pages);
	
	echo json_encode($pages);

}


?>
