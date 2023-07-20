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

$titles = array(
895, // Wrightia
153166, // Richardiana
8113, // SIDA
12678, // Phytologia
889, // North American flora
44786, // Bull. New York Bot. Gard.
259, // Leaflets of Philippine botany
49730, // Bulletin de l'Herbier Boissier
327, // Revis. Gen. Pl.
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
