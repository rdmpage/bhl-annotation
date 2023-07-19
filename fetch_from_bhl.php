<?php

error_reporting(E_ALL);

// fetch data from BHL and store on disk

require_once(dirname(__FILE__) . '/lib/bhl.php');

// Annales du Jardin botanique de Buitenzorg
$titles = array(
3659
);

$titles = array(
40366,
53883, // Bulletin of the Natural History Museum. Botany series
2198, // Bulletin of the British Museum (Natural History) Botany
);

$deep = false;
//$deep = true;

$force = false;

$fetch_counter = 1;

foreach ($titles as $TitleID)
{
	$dir = $config['cache'] . '/' . $TitleID;

	if (!file_exists($dir))
	{
		$oldumask = umask(0); 
		mkdir($dir, 0777);
		umask($oldumask);
	}

	$title = get_title($TitleID, $dir);

	foreach ($title->Result->Items as $title_item)
	{
		$item = get_item($title_item->ItemID, $force, $dir);

		foreach ($item->Result->Parts as $part)
		{
			get_part($part->PartID, $force, $dir);
		}
	
		// don't get pages if we have lots 
		if ($deep)
		{
			foreach ($item->Result->Pages as $page)
			{
				get_page($page->PageID, $force, $dir);
				
				// Give server a break every 10 items
				if (($fetch_counter % 10) == 0)
				{
					$rand = rand(1000000, 3000000);
					echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
					usleep($rand);
				}
			}
		}
	}
}

?>
