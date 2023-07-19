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
function find_name_from_citation($name_id, $name_string, $citation, $maxerror = 2)
{
	$doc = parse($citation, false);
	
	$doc->target = $name_string;

	// print_r($doc);

	if (isset($doc->data->{'container-title'}))
	{
		$bhl_titles = match_bhl_title($doc->data->{'container-title'}, false);
		if (count($bhl_titles) > 0)
		{
			$doc->data->BHLTITLEID = $bhl_titles;
		}	
	}

	// tuple search
	if (isset($doc->data->BHLTITLEID))
	{
		// BHL page
		$doc->data = find_bhl_page_local($doc->data);
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
		
			if ($hits->total > 0)
			{
				$doc->data->hits[$id] = $hits;
			}
	
		}
	}
	
	if (isset($doc->data->BHLPAGEID))
	{
		foreach ($doc->data->BHLPAGEID as $PageID)
		{
			if (isset($doc->data->hits))
			{				
				if (isset($doc->data->hits[$PageID]))
				{
					//print_r($doc->data->hits[$PageID]);
				
					foreach ($doc->data->hits[$PageID]->selector as $selector)
					{								
						if (isset($selector->exact))
						{
							$keys = array();
							$values = array();

							$keys[]   = 'string_id';
							$values[] = '"' . $name_id . '"';							
							
							$keys[]   = 'string';
							$values[] = '"' . str_replace('"', '""', $doc->target) . '"';	
							
							$keys[]   = 'bhlpageid';
							$values[] = (Integer)$PageID;							
																				
							$keys[]   = 'prefix';
							$values[] = '"' . str_replace('"', '""', $selector->prefix) . '"';
							$keys[]   = 'exact';
							$values[] = '"' . str_replace('"', '""', $selector->exact) . '"';
							$keys[]   = 'suffix';
							$values[] = '"' . str_replace('"', '""', $selector->suffix) . '"';
							$keys[]   = 'range';
							$values[] = '"' . str_replace('"', '""', join(",", $selector->range)) . '"';
							$keys[]   = 'score';
							$values[] = $selector->score;
							
							// generate an id base don the basic info abiout the hit
							$keys[] = "id";
							$values[] = '"' . md5(join("", array_values($values))) . '"';

							// text useful for display and debugging
							if (isset($doc->data->text[$PageID]))
							{
								$keys[] = "text";
								$values[] = '"' . str_replace('"', '""', $doc->data->text[$PageID]) . '"';
							}
												
							echo 'REPLACE INTO annotation(' . join(',', $keys) . ') VALUES (' . join(',', $values) . ');' . "\n";


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
}

//----------------------------------------------------------------------------------------

$filename = "test.tsv";

$headings = array();
$row_count = 0;

$file_handle = fopen($filename, "r");
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
			
			// print_r($obj);	
						
			find_name_from_citation(
				$obj->id, 
				$obj->fullnamewithoutfamilyandauthors,
				$obj->publication . ' ' . $obj->collation
				);

		}
	}	
	$row_count++;	
	
}	



?>

