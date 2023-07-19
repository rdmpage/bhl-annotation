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


$str = 'Ann. Jard. Bot. Buitenzorg, ii. (1885) 99.';

//$str = 'Monogr. Syst. Bot. Missouri Bot. Gard., 103:113';

$targets = array(
'Acronia constricta' => 'Monogr. Syst. Bot. Missouri Bot. Gard., 103:113'
);

foreach ($targets as $name => $citation)
{
	$doc = parse($citation, false);
	
	$doc->target = $name;

	//print_r($doc);

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

	foreach ($doc->data->text as $id => $text)
	{
		if (1)
		{
			$hits = find_in_text(
				$doc->target, 
				$text, 
				isset($doc->ignorecase) ? $doc->ignorecase : true,
				isset($doc->maxerror) ? $doc->maxerror : 3	
				);
		}
		else
		{
			$hits = find_in_text_simple(
				$doc->target, 
				$text, 
				isset($doc->ignorecase) ? $doc->ignorecase : true,
				isset($doc->maxerror) ? $doc->maxerror : 2	
				);								
		}
		
		if ($hits->total > 0)
		{
			$doc->data->hits[$id] = $hits;
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
					foreach ($doc->data->hits[$PageID]->selector as $selector)
					{								
						if (isset($selector->exact))
						{
			
							$terms = array();
							$terms[] = $PageID;
							$terms[] = $doc->target;
							$terms[] = $selector->prefix;
							$terms[] = $selector->exact;
							$terms[] = $selector->suffix;
							$terms[] = $selector->score;
							$terms[] = join(",", $selector->range);
				
							echo join("|", $terms) . "\n";
						}
					}
				}
			}
			
		}
	
	}
	
		
}


?>

