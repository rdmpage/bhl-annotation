<?php

require_once (dirname(__FILE__) . '/lib/bhl.php');
require_once (dirname(__FILE__) . '/lib/compare.php');
require_once (dirname(__FILE__) . '/sqlite.php');
require_once (dirname(__FILE__) . '/microparser.php');
require_once (dirname(__FILE__) . '/lib/textsearch.php');


//----------------------------------------------------------------------------------------
function match_bhl_title($text, $debug = false)
{
	$TitleIDs = array();
	
	if (count($TitleIDs) == 0)
	{
		// try approx match
		$like_title = preg_replace('/,/', ' ', $text);
		$like_title = preg_replace('/\./', ' ', $like_title);
		$like_title = trim($like_title);
		$like_title = preg_replace('/\s+/', '% ', $like_title);	
		$like_title .= '%'; 	
		$like_title = preg_replace('/’/u', "'", $like_title);
		$like_title = '%' . $like_title; 
		
		// fix some abbreviations (can we do this more cleverly?)
		$like_title = preg_replace('/Annls/i', "Annals", $like_title);
		$like_title = preg_replace('/natn/i', "National", $like_title);
		$like_title = preg_replace('/Ztg/i', "Zeitung", $like_title);
		
		// echo $title;
		
		$sql = 'SELECT DISTINCT TitleID, title FROM bhl_title WHERE title LIKE "' . addcslashes($like_title, '"') . '" COLLATE NOCASE;';
	
		if ($debug)
		{
			echo "\n$sql\n";
		}
	
		$query_result = do_query($sql);
		
		// get best match...
		$max_score = 0;
				
		foreach ($query_result as $row)
		{		
			$result = compare_common_subsequence(
				$text, 
				$row->title,
				false);

			if ($result->normalised[1] > 0.95)
			{
				// one string is almost an exact substring of the other
				if ($result->normalised[1] > $max_score)
				{
					$max_score = $result->normalised[1];
					$TitleIDs = array((Integer)$row->TitleID);
				}
			}
		}
		
		
		// hard coding
		if (count($TitleIDs) == 0)
		{
			switch ($text)
			{
				case 'Bull. Brit. Mus. (Nat. Hist.), Bot.':
					$TitleIDs[] = 2198;
					break;
					
				case 'Bull. Nat. Hist. Mus. London, Bot.':
					$TitleIDs[] = 53883;
					break;
					
				case 'Entomologist\'s Rec. J. Var.':
					$TitleIDs[] = 8408;
					break;
					
				case 'Exot. Microlepid.':
					$TitleIDs[] = 53883;
					break;
					
				case 'Prodr. (DC.)':
					$TitleIDs[] = 286;
					break;
					
				case 'Fieldiana, Bot.':
				case 'in Fieldiana, Bot.':
					$TitleIDs[] = 42247;
					break;
					
				case 'Sida':
					$TitleIDs[] = 8113;
					break;
					
				case 'Symb. Antill. (Urban)':
				case 'Symb. Antill. (Urban).':
					$TitleIDs[] = 144;
					break;
					
				default:
					break;
			}
		
		}
		

	}
	
	
	return $TitleIDs;
}

//----------------------------------------------------------------------------------------
function find_bhl_page_local($doc)
{
	global $config;
	
	// sanity check
	$go = isset($doc->BHLTITLEID) && isset($doc->volume) && isset($doc->page);
	
	if (!$go)
	{
		return $doc;
	}
	
	$sql = 'SELECT bhl_tuple.PageID, text 
			FROM bhl_tuple 
			INNER JOIN bhl_page USING(PageID)
			WHERE '
		. ' TitleID=' . $doc->BHLTITLEID[0]
		. ' AND sequence_label="' . str_replace('"', '""', $doc->volume) . '"'
		. ' AND page_label="' . str_replace('"', '""', $doc->page)  . '"';
		
	// echo $sql . "\n";

	$results = do_query($sql);
	
	//print_r($result);
	
	foreach ($results as $result)
	{
		$doc->BHLPAGEID[] = $result->PageID;
		
		$text = '';
				
		// do we have text locally?
		$basedir = $config['cache'] . '/' . $doc->BHLTITLEID[0];
		$filename = $basedir . '/page-' . $result->PageID . '.json';
		
		if (!file_exists($filename))
		{
			// nope, so try and fetch JSON file
			get_page($result->PageID, true, $basedir);
		}
		
		// try again
		if (file_exists($filename))
		{
			$json = file_get_contents($filename);
			$obj = json_decode($json);
			//print_r($obj);
			
			$text = $obj->Result->OcrText;
		}
		
		// add text if we have it?
		if ($text != '')
		{
			$text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));
			$doc->text[$result->PageID] = $text;
		}
	}
	
	return $doc;
}

//----------------------------------------------------------------------------------------
function find_name_from_citation($name_id, $name_string, $citation, $maxerror = 1, $debug = false)
{
	$result = 'NOT PARSED';
	
	// HACK!!!!!!
	$citation = preg_replace('/^in\s+/', '', $citation);

	$doc = parse($citation, true);
	
	$doc->target = $name_string;

	if ($debug)
	{
		print_r($doc);
	}

	if (isset($doc->data->{'container-title'}))
	{
		$result = 'PARSED';
		
		$bhl_titles = match_bhl_title($doc->data->{'container-title'}, false);
		if (count($bhl_titles) > 0)
		{
			$doc->data->BHLTITLEID = $bhl_titles;
		}	
	}
	else
	{
		return $result;		
	}
	
	if ($debug)
	{
		print_r($doc);
	}

	// tuple search
	if (isset($doc->data->BHLTITLEID))
	{
		// BHL page
		$doc->data = find_bhl_page_local($doc->data);
	}
	else
	{
		$result = 'NOTITLE';
		return $result;	
	}
	
	if ($debug)
	{
		print_r($doc);
	}
	
	if (isset($doc->data->text))
	{
		foreach ($doc->data->text as $id => $text)
		{
			if (1)
			{
				$hits = find_in_text(
					$doc->target, 
					$text, 
					isset($doc->ignorecase) ? $doc->ignorecase : true,
					isset($doc->maxerror) ? $doc->maxerror : $maxerror 	
					);
					
				//print_r($hits);
			}
			else
			{
				$hits = find_in_text_simple(
					$doc->target, 
					$text, 
					isset($doc->ignorecase) ? $doc->ignorecase : true,
					isset($doc->maxerror) ? $doc->maxerror : $maxerror 	
					);								
			}
		
			if (isset($hits->total) && $hits->total > 0)
			{
				$doc->data->hits[$id] = $hits;
			}
	
		}
	}
	
	if (isset($doc->data->BHLPAGEID))
	{
		$result = 'NOT FOUND';
	
		foreach ($doc->data->BHLPAGEID as $PageID)
		{
		
			// we have matched to a BHL page so store that
			$keys = array();
			$values = array();

			$keys[]   = 'string_id';
			$values[] = '"' . $name_id . '"';							
		
			$keys[]   = 'string';
			$values[] = '"' . str_replace('"', '""', $doc->target) . '"';	
			
			$keys[]   = 'citation';
			$values[] = '"' . str_replace('"', '""', $citation) . '"';	
								
			$keys[]   = 'bhlpageid';
			$values[] = (Integer)$PageID;		
			
			$id_values = array($citation, $PageID);				
		
			// generate a unique id based on the basic info about the hit
			$keys[] = "id";
			$values[] = '"' . md5(join("", $id_values)) . '"';
		
			echo 'REPLACE INTO page(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";
		
			// OK, now check whether we have a text match
			if (isset($doc->data->hits))
			{				
				if (isset($doc->data->hits[$PageID]))
				{
					//print_r($doc->data->hits[$PageID]);
				
					foreach ($doc->data->hits[$PageID]->selector as $selector)
					{								
						if (isset($selector->exact))
						{
							$id_values = array();
						
							$keys = array();
							$values = array();

							$keys[]   = 'string_id';
							$values[] = '"' . $name_id . '"';							
							
							$keys[]   = 'string';
							$values[] = '"' . str_replace('"', '""', $doc->target) . '"';	
							
							$id_values[] = strtolower(str_replace(' ', '-', $doc->target));
							
							$keys[]   = 'bhlpageid';
							$values[] = (Integer)$PageID;		
							
							$id_values[] = $PageID;				
																				
							$keys[]   = 'prefix';
							$values[] = '"' . str_replace('"', '""', $selector->prefix) . '"';
							$keys[]   = 'exact';
							$values[] = '"' . str_replace('"', '""', $selector->exact) . '"';
							$keys[]   = 'suffix';
							$values[] = '"' . str_replace('"', '""', $selector->suffix) . '"';
							
							$keys[]   = 'start';
							$values[] = $selector->range[0];

							$keys[]   = 'end';
							$values[] = $selector->range[1];
							
							$id_values[] = $selector->range[0];
							$id_values[] = $selector->range[1];
														
							$keys[]   = 'score';
							$values[] = $selector->score;
							
							// generate an id based on the basic info about the hit
							$keys[] = "id";
							//$values[] = '"' . md5(join("", array_values($values))) . '"';
							$values[] = '"' . join("-", array_values($id_values)) . '"';

							// text useful for display and debugging
							if (isset($doc->data->text[$PageID]))
							{
								$keys[] = "text";
								$values[] = '"' . str_replace('"', '""', $doc->data->text[$PageID]) . '"';
							}
									
							// we have a match to the page text			
							echo 'REPLACE INTO annotation(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";

							//echo $doc->target . "\n";

							$result = 'FOUND';

							/*
							$terms = array();
							$terms[] = $name_id;
							$terms[] = $PageID;
							$terms[] = $doc->target;
							$terms[] = urlencode($selector->prefix);
							$terms[] = urlencode($selector->exact);
							$terms[] = urlencode($selector->suffix);
							$terms[] = $selector->score;
							$terms[] = join(",", $selector->range);
				
							echo join("|", $terms) . "\n";
							*/
						}
					}
				}
			}
			
		}
	
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------

// settings----------------

$show_errors = false;
//$show_errors = true;

$try_again = true;
$try_again = false;

$max_error = 3;

$debug = false;
//$debug = true;

// process data-------------

$basedir = 'data';
$basedir = '.';

$files = scandir($basedir);

// debug
$files = array(
//'test.tsv',
//'Prodr. (DC.).tsv',
'data/721.tsv'
);

foreach ($files as $filename)
{
	if (preg_match('/\.tsv$/', $filename))
	{
		$headings = array();
		$row_count = 0;

		$not_parsed = array();
		$not_found = array();

		$file_handle = fopen($basedir . '/' . $filename, "r");
		while (!feof($file_handle)) 
		{
			$line = trim(fgets($file_handle));
		
			$row = explode("\t",$line);
	
			$go = is_array($row) && count($row) > 1;
	
			if ($go)
			{
				if ($row_count == 0)
				{
					$headings = $row;		
				}
				else
				{
					$obj = new stdclass;
			
					foreach ($row as $k => $v)
					{
						if ($v != '')
						{
							$obj->{$headings[$k]} = $v;
						}
					}
			
					//print_r($obj);
					
					// special handling for specific databases
					
					// IPNI---------------------------------------------------------------
					if (isset($obj->fullnamewithoutfamilyandauthors))
					{
						$obj->string = $obj->fullnamewithoutfamilyandauthors;
					}

					if (isset($obj->publication) && isset($obj->collation))
					{
						$obj->citation = $obj->publication . ', ' . $obj->collation;
					}	
					
					// look for match for name					
						
					$result = find_name_from_citation(
						$obj->id, 
						$obj->string,
						$obj->citation,
						$max_error,
						$debug
						);
				
					/*
					// need to parse name and see if we can do better
					if ($result == 'NOT FOUND' && $try_again)
					{
						// try abbreviated genus name
						if (preg_match('/^([A-Z])\w+\s+(\w+)$/', $obj->fullnamewithoutfamilyandauthors, $m))
						{
							$result = find_name_from_citation(
							$obj->id, 
							$m[1] . '. ' . $m[2],
							$citation,
							$max_error
							);
						}
					}
					*/
				
					echo "-- $result\n";
			
					switch ($result)
					{
						case 'NOT PARSED':
							$not_parsed[] = $obj->citation;
							break;

						case 'NOT FOUND':
							$not_found[] = $obj->string . "|" . $obj->citation;
							break;
			
						default:
							break;
			
					}

				}
			}	
			$row_count++;	
	
		}	

		if ($show_errors)
		{
			print_r($not_parsed);
			print_r($not_found);
		}
	}
}


?>

