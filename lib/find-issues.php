<?php

// Some BHL items have more than volume, or may have multiple issues, each
// starting at page 1. This means multiple pages in an item can have
// the same number. To add precision we could try and sort pages into
// separate series, and then create links between each page and the correpsonding series.
// For exmaple, https://www.biodiversitylibrary.org/item/32857 has 16 issues in a
// single volume. By finding distinct consecutive series of page numbers we can sort
// them into distinct issues.

// To do: these series may be issues within a volume, volumes within an item, or some
// combinaton of all of these!

require_once(dirname(__FILE__) . '/bhl.php');

//----------------------------------------------------------------------------------------
// Get (cleaned) page number
function get_page_number($PageNumbers)
{
	$value = '';
	
	if (isset($PageNumbers[0]->Number) && ($PageNumbers[0]->Number != ''))
	{
		$value = $PageNumbers[0]->Number;
		$value = preg_replace('/Page%/', '', $value);
		$value = preg_replace('/(Pl\.?(ate)?)%/', '$1 ', $value);
	}
		
	return $value;
}

//----------------------------------------------------------------------------------------
// Find sets of sequential page labels within an item. The simplest case is we have 
// a single, ascending sequence of page numbers. But some items may have several
// sets of pages numbers, for example if item contains more than one volume
function find_sequences($item_data)
{
	$result = new stdclass;
	$result->ItemID = $item_data->Result->ItemID;
	$result->TitleID = $item_data->Result->PrimaryTitleID;
		
	$result->scan_order = array();
	
	$result->sequence = array();		// 2D array to hold sequences of page numbers
	$result->labels = array(); 			// list of unique page labels
	$result->sequence_labels = array();
	
	$page_counter = 0;
	$sequence_counter = 0; // counter for number of series
			
	foreach ($item_data->Result->Pages as $page_summary)
	{
		// store order of page in scan
		$result->scan_order[$page_summary->PageID] = $page_counter++;
	
		// does page have a label?
		$label = get_page_number($page_summary->PageNumbers);
		
		if ($label != '')
		{	
			// create current sequence if it doesn't exist	
			if (!isset($result->sequence[$sequence_counter]))
			{
				$result->sequence[$sequence_counter] = array();
			}
				
			// if we've seen this label before in the current sequence assume 
			// it belongs to the next sequence, which we now create
			if (isset($result->sequence[$sequence_counter][$label]))
			{
				// new series
				$sequence_counter++;
				$result->sequence[$sequence_counter] = array();
			}
			
			// add label to sequence and store page data		
			$page = new stdclass;
			$page->id = $page_summary->PageID;
			$page->label = $label;
			
			$result->sequence[$sequence_counter][$label] = $page;
		
			// keep track of unique labels for pages
			if (!in_array($label, $result->labels))
			{
				$result->labels[] = $label;
			}
		}			
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Display sequences as a text-based matrix for debugging
function display_sequences($result)
{
	$num_sequences = count($result->sequence);
	
	foreach ($result->labels as $label)
	{
		echo str_pad($label, 10, ' ', STR_PAD_LEFT);
		echo " | ";
		
		// if only one series then we always have a page with this label
		if ($num_sequences == 1)
		{
			echo 'x';
		}
		else
		{
			// mutliple series, need to check whether this label is in series
			for ($i = 0; $i < $num_sequences; $i++)
			{
				if (isset($result->sequence[$i][$label]))
				{
					echo 'x';		
				}
				else
				{
					echo ' ';
				}
			}	
		}
		echo "\n";
	}
}

//----------------------------------------------------------------------------------------
// Generate SQL to store
function tuples_to_sql($item_series)
{
	$tuples = array();

	foreach ($item_series->scan_order as $id => $order)
	{
		$tuples[$id] = new stdclass;
		$tuples[$id]->PageID = $id;		
		$tuples[$id]->TitleID = $item_series->TitleID;
		$tuples[$id]->ItemID = $item_series->ItemID;
		$tuples[$id]->scan_order = $order;
	}
	
	foreach ($item_series->sequence as $sequence_number => $labels)
	{
		foreach ($labels as $label)
		{
			$tuples[$label->id]->sequence = $sequence_number;
			
			if (isset($item_series->sequence_labels[$sequence_number]))
			{
				$tuples[$label->id]->sequence_label = $item_series->sequence_labels[$sequence_number];
			}
			
			$tuples[$label->id]->page_label = $label->label;
		}
	}
	
	// print_r($tuples);
	
	$key_names = array('PageID', 'TitleID', 'ItemID', 'scan_order', 'sequence', 'sequence_label', 'page_label');
	
	foreach ($tuples as $id => $obj)
	{	
		$keys = array();
		$values = array();

		foreach ($key_names as $k)
		{
			if (isset($obj->{$k}))
			{
				$keys[] = $k;
			
				switch ($k)
				{
					// text
					case 'page_label':
					case 'sequence_label':
						$values[] = '"' . $obj->{$k} . '"';
						break;

					// numbers
					case 'PageID':
					case 'TitleID':
					case 'ItemID':
					case 'scan_order':
					case 'series':
					default:
						$values[] = $obj->{$k};
						break;
				}
			}
		}		
		echo 'REPLACE INTO bhl_tuple(' . join(",", $keys) . ') VALUES(' . join(",", $values) . ');' . "\n";
	}

}

if (0)
{

	// Proc US Nat Mus (multiple articles in same volume, all start on page 1)
	$TitleID = 7519;
	$ItemID = 32857;

	if (0)
	{
	// single series
	$TitleID = 119777;
	$ItemID = 209323;
	}

	if (0)
	{
	// single series
	$TitleID = 45481; // Genera insectorum
	$ItemID = 107651; // fasc. 1-11 (two of which are merged :(
	$ItemID = 105421;
	}
	
	
	$TitleID = 11933; 
	$ItemID = 44640;
	
	
	$TitleID = 14688; 
	$ItemID = 265868;
	
	

	$basedir = $config['cache'] . '/' . $TitleID;

	$files = scandir($basedir);

	$files = array('item-' . $ItemID . '.json');

	foreach ($files as $filename)
	{
		if (preg_match('/item-(?<id>\d+)\.json$/', $filename, $m))
		{	
			$json = file_get_contents($basedir . '/' . $filename);

			$item_data = json_decode($json);
		
		
			$item_series = find_sequences($item_data);
		
			// debugging
			display_sequences($item_series);
		
			//tuples_to_sql($item_series);
		
		
			// print_r($item_series);
			
		
			// what are the correspodning series in the metadata?
			$range = array();	
			if (preg_match('/no.(\d+)-(\d+)/', $item_data->Result->Volume, $m))
			{
				for ($i = $m[1]; $i <= $m[2]; $i++)
				{
					$range[] = $i;
				}
			}
			print_r($range);
		
			if (count($range) == count($item_series->sequence))
			{
				echo "Series match\n";
			}
		
	
			// add to RDF (to do)
			// need to come up with a URI for the parent (e.g., issue)
		

		
		}
	}

}

?>
