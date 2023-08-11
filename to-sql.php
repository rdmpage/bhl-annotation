<?php

// Generate SQL for BHL metadata

error_reporting(E_ALL);

// Generate RDF
require_once(dirname(__FILE__) . '/lib/bhl.php');
require_once(dirname(__FILE__) . '/lib/parse-volume.php');
require_once(dirname(__FILE__) . '/lib/find-issues.php');


//----------------------------------------------------------------------------------------
// Add page from page data
function page_to_sql($PageID, $basedir = '')
{
	$page_data = get_page($PageID, false, $basedir);
	
	$number = '';

	// page numbers
	if (isset($page_data->Result->PageNumbers[0]))
	{
		$number = get_page_number($page_data->Result->PageNumbers);			
	}
	
	$keys = array();
	$values = array();
	
	$keys[] = 'PageID';
	$values[] = $page_data->Result->PageID;
	
	$keys[] = 'ItemID';
	$values[] = $page_data->Result->ItemID;		
	
	if ($number != '')
	{
		$keys[] = 'number';
		$values[] = '"' . str_replace('"', '""', $number) . '"';				
	}
	
	if ($page_data->Result->OcrText != '')
	{
		$text = $page_data->Result->OcrText;		
		$text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));

		// remove double end of lines
		$text = preg_replace('/\n\n/', "\n", $text);
		
		//$text = preg_replace("/\n/", '\n', $text);
		
		$keys[] = 'text';
		$values[] = '"' . str_replace('"', '""', $text) . '"';				
	}
	
	echo 'REPLACE INTO bhl_page(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";
	
	/*
	// what is the best way to model this?
	// can we make these annotations?
	foreach ($page_data->Result->Names as $Name)
	{
		// Taxonomic name 
		$uri = '';
		
		if ($uri == '')
		{
			if ($Name->NameBankID != '')
			{
				$uri = 'urn:lsid:ubio.org:namebank:' . $Name->NameBankID;
			}
		}	
		
		if ($uri != '')
		{
			$taxonName = $graph->resource($uri, 'schema:TaxonName');			
		}
		else
		{
			$taxonName = create_bnode($graph,  'schema:TaxonName');
		}
		
		// name strings
		$taxonName->add('schema:name', $Name->NameFound);			
		if ($Name->NameConfirmed != '')
		{
			if ($Name->NameFound != $Name->NameConfirmed)
			{
				$taxonName->add('schema:alternateName', $Name->NameConfirmed);
			}
		}
		
		// page is about this name		
		$page->addResource('schema:about', $taxonName);		
		
		// page is about this taxon (EOL)
		if ($Name->EOLID != '')
		{
			$uri = 'https://eol.org/pages/' . $Name->EOLID;
			$page->addResource('schema:about', $uri);				
		}
	
	}
	*/
}


//----------------------------------------------------------------------------------------
// get page and part data
function item_to_sql($ItemID, $deep = false, $basedir = '')
{
	$item_data = get_item($ItemID, false, $basedir);
	
	// sequence/volume/issue
	
	// map pages to chunk
	
	// maybe need a table [title, item, sequence, sequence_name, pageid]
	// this is what we would do the lookup ON
	// by default, only one sequence per item, labelled by the Volume
	// if more than 1 sequences then each is labelled by indivual name(
	// if everything in table indexed by item we can edit to handle cases where things go wrong.
	
	// get tuples representing page sequences in an item
	$item_series = find_sequences($item_data);
	
	//print_r($item_series );
			
	if (isset($item_data->Result->Volume) && ($item_data->Result->Volume != ''))
	{		
		// parse into clean metadata
		$parse_result = parse_volume($item_data->Result->Volume);

		// get labels for series that might exist in item
		if ($parse_result->parsed)
		{
			//print_r($parse_result);
			
			$sequence_labels = array();
			
			// 1. Assume series are in volumes
			if (isset($parse_result->volume))
			{
				foreach ($parse_result->volume as $volume)
				{
					$sequence_labels[] = $volume;
				}
			}
			
			$num_series = count($item_series->sequence);
			$num_labels = count($sequence_labels);
						
			// sanity check?
			if ($num_series > $num_labels)
			{
				// ok we have a problem, we don't have enough sequence labels
				// this could be because we have multiple issues within a volume, and the 
				// issues are all numbered from 1.
				// 
				// as a hack, apply same label across sequences
				
				if ($num_labels == 1)
				{
					for ($i = 1; $i < $num_series; $i++)
					{
						$sequence_labels[] = $sequence_labels[0];
					}
				}
				
				
				
			}

			// add labels			
			$item_series->sequence_labels = $sequence_labels;
		}
		else
		{
			echo "-- Failed to parse " . $item_data->Result->Volume . "\n";
			//exit();
		}
		
	}
	
	//exit();
	
	// output sequence(s) of pages
	tuples_to_sql($item_series);
	
	// pages -----------------------------------------------------------------------------
	// Get basic information for pages
	foreach ($item_data->Result->Pages as $page_summary)
	{
		$number = '';
	
		// page numbers
		if (isset($page_summary->PageNumbers[0]))
		{
			$number = get_page_number($page_summary->PageNumbers);			
		}
				
		if ($deep)
		{
			// do pages in detail, including text
			page_to_sql($page_summary->PageID, $basedir);
		}
		else
		{
			// we will just add this level of detail
			$keys = array();
			$values = array();
			
			$keys[] = 'PageID';
			$values[] = $page_summary->PageID;
			
			$keys[] = 'ItemID';
			$values[] = $page_summary->ItemID;		
			
			if ($number != '')
			{
				$keys[] = 'number';
				$values[] = '"' . str_replace('"', '""', $number) . '"';					
			}
		
			echo 'REPLACE INTO bhl_page(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";
		}
	}	
	
	/*
	// parts ----------------------------------------------------------------------------
	foreach ($item_data->Result->Parts as $part_summary)
	{
		$part = $graph->resource($part_summary->PartUrl, 'schema:CreativeWork');
		
		// specific kind of work
		switch ($part_summary->GenreName)
		{
			case 'Article':
				$part->addResource('rdf:type','schema:ScholarlyArticle');
				break;

			case 'Chapter':
				$part->addResource('rdf:type','schema:Chapter');
				break;
		
			default:
				break;		
		}		
		
		// a part is a part of an item
		$part->addResource('schema:isPartOf', $item);
		
		// part name
		$part->add('schema:name', $part_summary->Title);
		
		// do we have a DOI?
		if ($part_summary->Doi != '')
		{
			add_doi($graph, $part, $part_summary->Doi);
		}
		
		// to get more info we need the actual part data
		
		// link pages in this part to the part (they are already linked to item)
		$part_data = get_part($part_summary->PartID, false, $basedir);
		
		foreach($part_data->Result->Pages as $page_data)
		{
			$page = $graph->resource($page_data->PageUrl, 'schema:CreativeWork');
			$page->addResource('schema:isPartOf', $part);
		}		
		
	}
	*/

	
}

//----------------------------------------------------------------------------------------
function title_to_sql($TitleID, $basedir = '')
{
	$title_data = get_title($TitleID, $basedir);
	
	// basic title info
	
	$keys = array();
	$values = array();
	
	
	$keys[] = 'TitleID';
	$values[] = $TitleID;

	$keys[] = 'title';
	$values[] = '"' . str_replace('"', '""', $title_data->Result->FullTitle) . '"';
	
	// think about adding alternative titles
	
	// identifiers
	foreach ($title_data->Result->Identifiers as $identifier)
	{
		switch ($identifier->IdentifierName)
		{
			case 'ISSN':
				$keys[] = 'issn';
				$values[] = '"' . str_replace('"', '""', $identifier->IdentifierValue) . '"';
				break;
				
			case 'OCLC':
				$keys[] = 'oclc';
				$values[] = '"' . str_replace('"', '""', $identifier->IdentifierValue) . '"';
				break;
				
			default:
				break;		
		}	
	}
	
	// do we have a DOI?
	if ($title_data->Result->Doi != '')
	{
		$keys[] = 'doi';
		$values[] = '"' . str_replace('"', '""', $title_data->Result->Doi) . '"';
	}
	
	echo 'REPLACE INTO bhl_title(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";
	
	
	// items are parts of the title
	foreach ($title_data->Result->Items as $item_summary)
	{
		$keys = array();
		$values = array();
		
		$keys[] = 'ItemID';
		$values[] = $item_summary->ItemID;		
	
		$keys[] = 'TitleID';
		$values[] = $TitleID;
		
		// and volume string as "title" of this volume"
		if (isset($item_summary->Volume) && ($item_summary->Volume != ''))
		{
		
			$keys[] = 'title';
			$values[] = '"' . str_replace('"', '""', $item_summary->Volume ) . '"';
		}		
		
		echo 'REPLACE INTO bhl_item(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";		
	}
}


if (1)
{
	
	
	if (1)
	{
		// parts
		$TitleID = 3659; 
		$files = array(
			'title-' . $TitleID . '.json',
			'item-24941.json',
			);
			
		// parts
		$TitleID = 40366; 
		$files = array(
			'title-' . $TitleID . '.json',
			'item-294985.json',
			);

			
	}
	
	// all for a title
	
	$titles = array(
	/*
895, // Wrightia
153166, // Richardiana
8113, // SIDA
12678, // Phytologia
889, // North American flora
44786, // Bull. New York Bot. Gard.
259, // Leaflets of Philippine botany
49730, // Bulletin de l'Herbier Boissier
327, // Revis. Gen. Pl.
*/
60, // Bot. Jahrb. Syst.
626, // Linnaea
454, // Fl. Bras. (Martius)
286, // Prodr. (DC.)
250, // Pflanzenr. (Engler)
687, // Contr. U.S. Natl. Herb.
702, // Annals of the Missouri Botanical Garden
721, // Rhodora
480, // J. Arnold Arbor.
64, // Flora
42246, // Publication. Field Museum of Natural History Botanical series
744, // Novon
59986, // Gray Herb
359, // Bulletin de la Société botanique de France
276, // Repertorium specierum novarum regni vegetabilis
15369, // Pittonia
2087, // Journal of the Washington Academy of Sciences
16515, // Flora australiensis:
128759, // Nuytsia
144, // Symb. Antill. (Urban).
42247, // Fieldiana, Bot.
65344, // Madroño
77306, // The Gardens' bulletin, Singapore

);

$titles = array(
50489, // Memoirs of the New York Botanical Garden

);

$titles=array(

11516,	// Transactions of the Entomological Society of London

7414, 	// journal of the Bombay Natural History Society *
58221, 	// List of the specimens of lepidopterous insects in the collection of the British Museum *
53882, 	// Bulletin of the British Museum (Natural History) Entomology *

112965, 	// Muelleria: An Australian Journal of Botany
157010, 	// Telopea: Journal of plant systematics

128759, // Nuytsia: journal of the Western Australian Herbarium

44963, 	// Proceedings of the Zoological Society of London *
45481, 	// Genera insectorum *


116503, 	// Annals of the Transvaal Museum *
12260, 	// Deutsche entomologische Zeitschrift Iris *

6525, 	// Proceedings of the Linnean Society of New South Wales *
168319, 	// Transactions of the Royal Society of South Australia *

79076, 	// Nota lepidopterologica *


87655,	// Horae Societatis Entomologicae Rossicae, variis sermonibus in Rossia usitatis editae
2510,	// Proceedings of the Entomological Society of Washington
8630, // Stettiner Entomologische Zeitung
8641, // Entomologische Zeitung
47036, //Jahresbericht des Entomologischen Vereins zu Stettin
8646, // The Entomologist's monthly magazine
6928, // Annals of the South African Museum *
10088, // Tijdschrift voor entomologie

14019, 		// The Proceedings of the Royal Society of Queensland
8187, 		// Bulletin de la Société entomologique de France
82093, 		// Lepidopterorum catalogus
7422, 		// Canadian entomologist
46204, 		// Berliner entomologische Zeitschrift 
46203, 48608, 48608, //  Deutsche entomologische Zeitschrift

60455, 		// Atti della Società italiana di scienze naturali
16255, 		// Atti Soc. ital. sci. nat., Mus. civ. stor. nat. Milano
2356, 		// Entomological news


15774, // Annals and magazine of natural history*
62014, // Die Grossschmetterlinge der Erde *

706, //Curtis
307, // bot mag

119777, // vol 1
119421, // vol 2
119424, // vol 3
119597, // vol 4
119515, // vol 5
119516, // vol 6

9241, // Exotic Lepidoptera

79076, // Nota lepidopterologica *

3882, // Novitates zoologicae *

68619 , 	// Insects of Samoa *
8089, 		// Journal of the New York Entomological Society *
16211, 		// Bulletin of the Brooklyn Entomological Society
8981, 		// Revue suisse de zoologie

49392, 49174, 43750, // Stuttgarter Beiträge zur Naturkunde

7519, 		// Proceedings of the United States National Museum
58221, // List of the specimens of lepidopterous insects in the collection of the British Museum *
11938, // Annales de la Société entomologique belge
11933, // Annales de la Société entomologique belge
14688, // The Sarawak Museum journal
730, // Biologia Centrali-Americana :zoology, botany and archaeology
3625, // Annals of the Royal Botanic Gardens, Peradeniya
62643, // Journal of the Lepidopterists' Society

58209,
169051,

);


$titles = array(
42247, // Fieldiana, Bot.

);

$titles=array(
//114584, //	Acta Bot. Mex.
//314, // Notulae systematicae
//117069, // Rodriguésia
//3943, // Proceedings of the California Academy of Sciences, 4th series
//43943, // American fern journal
//77306, 

//64,

//9241
8408
);

// debugging
if (0)
{
		$deep = false;

		$TitleID = 50489; 
		$files = array(
			'title-' . $TitleID . '.json',
			'item-150965.json',
			);
			
		$TitleID = 42247; 
		$files = array(
			'title-' . $TitleID . '.json',
			'item-19685.json',
			);
			
			

		$basedir = $config['cache'] . '/' . $TitleID;	
		
		foreach ($files as $filename)
		{
		
		
			// title
			if (preg_match('/title-(?<id>\d+)\.json$/', $filename, $m))
			{	
				//title_to_sql($m['id'], $basedir);
			}	
		
			// item
			if (preg_match('/item-(?<id>\d+)\.json$/', $filename, $m))
			{	
				item_to_sql($m['id'], $deep, $basedir);
			}			
		}


}
else
{
	// bulk 

	foreach ($titles as $TitleID)
	{
		$basedir = $config['cache'] . '/' . $TitleID;	
		$files = scandir($basedir);


		$deep = false;
		//$deep = true; // include text and names
	
	

		foreach ($files as $filename)
		{
			// title
			if (preg_match('/title-(?<id>\d+)\.json$/', $filename, $m))
			{	
				title_to_sql($m['id'], $basedir);
			}	
		
			// item
			if (preg_match('/item-(?<id>\d+)\.json$/', $filename, $m))
			{	
				item_to_sql($m['id'], $deep, $basedir);
			}			
		}
	}
}
}
 
?>
 