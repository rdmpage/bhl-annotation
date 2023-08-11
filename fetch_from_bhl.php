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

$titles=array(
114584, //	Acta Bot. Mex.
314, // Notulae systematicae
117069, // Rodriguésia
3943, // Proceedings of the California Academy of Sciences, 4th series
43943, // American fern journal
);


$titles=array(

43943, // American fern journal

8408, // The entomologist's record and journal of variation
);

$titles=array(
//9425,
169356, // Austrobaileya
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
		echo $title_item->ItemID . "\n";
		
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
