<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/php-approximate-search/approximate-search.php');

define ('FLANKING_LENGTH', 64);

//----------------------------------------------------------------------------------------
// https://kvz.io/reverse-a-multibyte-string-in-php.html
function mb_strrev ($string, $encoding = null) {
	if ($encoding === null) {
		$encoding = mb_detect_encoding($string);
	}

	$length   = mb_strlen($string, $encoding);
	$reversed = '';
	while ($length-- > 0) {
		$reversed .= mb_substr($string, $length, 1, $encoding);
	}

	return $reversed;
}

//----------------------------------------------------------------------------------------
// Get longest common subsequence of query in original text
// $X is query, $Y is target. Return start and end positions of match in target string
function compare($needle, $haystack)
{
	$obj = new stdclass;
	$obj->range = array();
	$obj->d = -1;
	
	$obj->needle = $needle;
	$obj->haystack = $haystack;
	
	$obj->left 	= '';
	$obj->right = '';
	$obj->bars 	= '';

	// compare two strings 
	$C = array();
	
	$X = $needle;
	$Y = $haystack;

	$m = mb_strlen($X);
	$n = mb_strlen($Y);
	
	// initialise start and end positions of match
	$obj->range= array($n, 0);	

	for ($i = 0; $i <= $m; $i++)
	{
		$C[$i][0] = 0;
	}
	for ($j = 0; $j <= $n; $j++)
	{
		$C[0][$j] = 0;
	}

	for ($i = 1; $i <= $m; $i++)
	{
		for ($j = 1; $j <= $n; $j++)
		{
			if (mb_substr($X, $i - 1, 1) == mb_substr($Y, $j - 1, 1))
			{
				$C[$i][$j] = $C[$i-1][$j-1] + 1;
			}
			else
			{
				$C[$i][$j] = max($C[$i][$j-1], $C[$i-1][$j]);
			}
		}
	}
	
	// score
	$obj->d = $C[$m][$n];
	
	/*
	echo "   ";
	for ($j = 1; $j <= $n; $j++)
	{
		echo str_pad(ord(mb_substr($Y, $j - 1, 1)) , 3, 'x', STR_PAD_LEFT);
	}
	echo "\n";
	for ($i = 1; $i <= $m; $i++)
	{
		echo mb_substr($X, $i - 1, 1) . ' ';
	
		for ($j = 1; $j <= $n; $j++)
		{
			echo str_pad($C[$i][$j], 3, ' ', STR_PAD_LEFT);
		}
		echo "\n";
	}
	*/
	
	// alignment (this is done in reverse order)
	// we use the alignment to get start and end positions of the match in haystack
		
	$i = $m;
	$j = $n;
	
	// Because we are getting the longest common subsequence irrespective of gaps, we
	// can sometimes get "ugly" alignments such as 
	// abascantu--s
    // |||||||||  |
    // abascantus s
	// To avoid these we go left in the score matrix until the scores decrease.
	
	while ($C[$i][$j] == $C[$i][$j-1] && $j > 0)
	{
		$j--;
	}
	
	while ($i > 0 && $j > 0)
	{
		if (mb_substr($X, $i - 1, 1) == mb_substr($Y, $j - 1, 1))
		{
			$obj->left .= mb_substr($X, $i - 1, 1);
			$obj->bars .= '|';
			$obj->right .= mb_substr($Y, $j - 1, 1);
			
			// update range
			$obj->range[1] = max($obj->range[1], $j);
			$obj->range[0] = min($obj->range[0], $j - 1);
			
			$i--;
			$j--;
		}
		else 
		if (($j > 0) and ($i == 0 or $C[$i][$j-1] >= $C[$i-1][$j]))
		{
			$obj->left .= '-';
			$obj->bars .= ' ';
			$obj->right .= mb_substr($Y, $j - 1, 1);
			
			$j--;
		
		}
		else 
		if (($i > 0) and ($j == 0 or $C[$i][$j-1] < $C[$i-1][$j]))
		{
			$obj->left .= mb_substr($X, $i - 1, 1);
			$obj->bars .= ' ';
			$obj->right .= '-';
			
			$i--;
		}
	}
	
	$obj->left = mb_strrev($obj->left);
	$obj->bars = mb_strrev($obj->bars);
	$obj->right = mb_strrev($obj->right);
	
	$obj->alignment = "\n" . join("\n", array($obj->left, $obj->bars, $obj->right));
	
	return $obj;	
}	

//----------------------------------------------------------------------------------------
function find_in_text($needle, $haystack, $case_insensitive = false, $max_error = 1)
{
	$output_html = true;
	//$output_html = false;
	
	if ($output_html)
	{
		// tags for HTML
		$tag = 'mark';
		$tag_starts = array();
		$tag_ends 	= array();
	}	
	
	$result = new stdclass;

	$query = $needle;
	$text = $haystack;
	
	$original_query = $query;
	
	if ($case_insensitive)
	{
		$text = mb_strtolower($text);
		$query = mb_strtolower($query);
	}

	// Search using approximate search
	$search = new Approximate_Search($query, $max_error);
	if ( $search->too_short_err )
	{
		 $result->message = "Unable to search for \"" . addcslashes($query, '"') . "\" - use longer pattern " .
		 "or reduce error tolerance.";
		 
		 return $result;
	}
	$result->matches = $search->search($text);
	
	// number of matches
	$result->total = 0;
	
	/*
	foreach ($result->matches as $match)
	{
		$result->total++;
	}
	*/
	
	// get list of hits in the inout text (text selectors)
	$result->selector = array();


	// If we have matches we want to get start and end positions of those matches in
	// the larger text so that we can display this (e.g., debugging)
	
	// Note also that apporx search algorithm can generate the "same hist" several times,
	// e.g. if max_error > 0 then the same substring of text may have > 1 (WTF!?)
	
	$unique_ranges = array();
	
	foreach ($result->matches as $pos => $d)
	{
		$text1 = $query;	
		$query_length = mb_strlen($query);
	
		// grab substring from target text, make it long enough to include mismatches	
		// by expanding either side by MAX_ERR
		$from 	= max(0, $pos - $query_length - $max_error);
		$to 	= min(mb_strlen($text), $pos + $max_error);	
		$text2 	= mb_substr($text, $from , $to - $from + 1);
		
		// get position of match in substring
		$alignment = compare($text1, $text2);
			
		// store this hit
		$hit = new stdclass;
		
		// store query string
		$hit->body = $original_query;
		
		// store the score of the alignment
		$hit->score = $query_length - $alignment->d;
		
		//$hit->alignment = $alignment;
		
		// location in haystack (i.e., text before any change in case)
		$start = $from + $alignment->range[0];
		$end = $from + $alignment->range[1];
		
		$hit->range = array($start, $end);
		
		// match in haystack
		$hit->exact = mb_substr($haystack, $start, $end - $start);
		
		$pre_length = min($start, FLANKING_LENGTH);
		$pre_start = $start - $pre_length;
	
		$hit->prefix = mb_substr($haystack, $pre_start, $pre_length, mb_detect_encoding($haystack)); 
		$post_length = min(mb_strlen($haystack, mb_detect_encoding($haystack)) - $end, FLANKING_LENGTH);		
		$hit->suffix = mb_substr($haystack, $end, $post_length, mb_detect_encoding($haystack)); 
		
		$range_key = join("-", $hit->range);
		if (!in_array($range_key, $unique_ranges))
		{		
			$result->total++;
			$unique_ranges[] = $range_key;
			$result->selector[] = $hit;
		}
		
		if ($output_html)
		{
			// tag the match
						
			if (!isset($tag_starts[$start]))
			{
				$tag_starts[$start] = array();
			}
			$tag_starts[$start][] = $tag;

			if (!isset($tag_ends[$end]))
			{
				$tag_ends[$end] = array();
			}
			$tag_ends[$end][] = $tag;
		}
	}
	
	if ($output_html)
	{
		// HTML for debugging
		
		// split origjnal text into array of characters
		$text_array = mb_str_split($haystack);

		$result->html = '';

		foreach ($text_array as $pos => $char)
		{

			if (isset($tag_ends[$pos]))
			{
				foreach ($tag_ends[$pos] as $tag)
				{
					$result->html .= '</' . $tag . '>';
				}
			}


			if (isset($tag_starts[$pos]))
			{		
				foreach ($tag_starts[$pos] as $tag)
				{
					$result->html .=  '<' . $tag . '>';
				}
			}

			$result->html .=  $char;

		}
	}


	return $result;
}

//----------------------------------------------------------------------------------------
// Very crude search for string (because more sophisticated apporaches seem to be problematic)
function find_in_text_simple($needle, $haystack, $case_insensitive = false, $max_error = 1)
{
	$output_html = true;
	//$output_html = false;
	
	if ($output_html)
	{
		// tags for HTML
		$tag = 'mark';
		$tag_starts = array();
		$tag_ends 	= array();
	}	
	
	$result = new stdclass;

	$query = $needle;
	$text = $haystack;
	
	$original_query = $query;
	
	if ($case_insensitive)
	{
		$text = mb_strtolower($text);
		$query = mb_strtolower($query);
	}
	
	$words = preg_split('/\s/', $text);
	
	// print_r($words);
	
	$num_words = count($words);
	
	$result->matches = array();
	
	$pos = 0;
	for ($i = 0; $i < $num_words; $i++)
	{
		//echo "[$pos] " . $words[$i] . "\n";
	
		$word_length = mb_strlen($words[$i]);
		$pos += $word_length;
		
		// [!\"#\＄%&\'\(\)\*\+,-\./:;<=>\?@\[\\\]\^_`{\|}~]?
		
		$have_match = false;
		
		if (!$have_match)
		{
			// exact match
			if (preg_match('/' . $needle . '/iu', $words[$i]))
			{
				$result->matches[] = $pos;
				$have_match = true;
			}
		}
		
		if (!$have_match)
		{
			// approx match, test string if it matches first two characters
			if (preg_match('/^' . mb_substr($needle, 0, 2) . '/iu', $words[$i]))
			{
				// we match the start of the string, check if rest is an OK match
				if (levenshtein($needle, $words[$i]) <= $max_error)
				{
					$result->matches[] = $pos;
					$have_match = true;
				}
			}
		}
		
		/*
		// this seems to work, but breaks alignment.
		// for example Cnemopsilus if we match on a starting G the alignment ignores that
		// and alugns startyig at nemopsilus. Need to fix.
		if (!$have_match)
		{
			// confusion matrix			
			$char_one = mb_substr($needle, 0, 1);
			$char_two = mb_substr($needle, 1, 1); 
			
			switch ($char_one)
			{
				case 'c':
				case 'C':
					$pattern = '/^[g]' . $char_two . '/iu';
					break;
			
				default:
					break;
			}
			
			if ($pattern != '')
			{
				//echo $pattern . "\n";
				if (preg_match($pattern, $words[$i]))
				{
					// we match the start of the string, check if rest is an OK match
					if (levenshtein($needle, $words[$i]) <= $max_error)
					{
						$result->matches[] = $pos;
						$have_match = true;
					}
				}
			}
		}
		*/
		
		
		
		// leading space
		$pos++;
	}
	
	// print_r($matches);
	
	// number of matches
	$result->total = 0;
	foreach ($result->matches as $match)
	{
		$result->total++;
	}
	
	// get list of hits in the inout text (text selectors)
	$result->selector = array();	
	
	foreach ($result->matches as $pos)
	{
		$text1 = $query;	
		$query_length = mb_strlen($query);
	
		// grab substring from target text, make it long enough to include mismatches	
		// by expanding either side by MAX_ERR
		$from 	= max(0, $pos - $query_length - $max_error);
		$to 	= min(mb_strlen($text), $pos + $max_error);	
		$text2 	= mb_substr($text, $from , $to - $from + 1);
				
		// get position of match in substring
		$alignment = compare($text1, $text2);
		
		// echo $alignment->alignment;
	
		// store this hit
		$hit = new stdclass;
		
		// store query string
		$hit->body = $original_query;
		
		// store the score of the alignment
		$hit->score = $query_length - $alignment->d;
		
		//$hit->alignment = $alignment;
		
		// location in haystack (i.e., text before any change in case)
		$start = $from + $alignment->range[0];
		$end = $from + $alignment->range[1];
		
		$hit->range = array($start, $end);
		
		// match in haystack
		$hit->exact = mb_substr($haystack, $start, $end - $start);
		
		$pre_length = min($start, FLANKING_LENGTH);
		$pre_start = $start - $pre_length;		
		
		$hit->prefix = mb_substr($haystack, $pre_start, $pre_length, mb_detect_encoding($haystack)); 
		$post_length = min(mb_strlen($haystack, mb_detect_encoding($haystack)) - $end, FLANKING_LENGTH);		
		$hit->suffix = mb_substr($haystack, $end, $post_length, mb_detect_encoding($haystack)); 
		
		$result->selector[] = $hit;
		
		if ($output_html)
		{
			// tag the match
						
			if (!isset($tag_starts[$start]))
			{
				$tag_starts[$start] = array();
			}
			$tag_starts[$start][] = $tag;

			if (!isset($tag_ends[$end]))
			{
				$tag_ends[$end] = array();
			}
			$tag_ends[$end][] = $tag;
		}
	}	
	
	if ($output_html)
	{
		// HTML for debugging
		
		// split origjnal text into array of characters
		$text_array = mb_str_split($haystack);

		$result->html = '';

		foreach ($text_array as $pos => $char)
		{

			if (isset($tag_ends[$pos]))
			{
				foreach ($tag_ends[$pos] as $tag)
				{
					$result->html .= '</' . $tag . '>';
				}
			}


			if (isset($tag_starts[$pos]))
			{		
				foreach ($tag_starts[$pos] as $tag)
				{
					$result->html .=  '<' . $tag . '>';
				}
			}

			$result->html .=  $char;

		}

		//$result->html .= '</pre>';
	}
	
	
	return $result;
}



// tests

if (0)
{

$text = '6 Proceedings of the Biological Society of Washington. 

NEMATOMA, new genus. 

Shells of ovate or elongate-ovate outline, covered with a thin perio- 
stracum. Nuclear whorls, judging from a fragment present, smooth, well 
roimded, with the last part of the last turn showing a feeble beginning of the 
postnuclear sculpture. Postnuclear whorls inflated, strongly rounded with 
an obsolete angle below the summit, which frequently gives the part 
between tliis and the summit a shouldered effect. The postnuclear whorls 
are marked by axial ribs, which evanesce on the base, and numerous spiral 
threads which are present on both spire and base. Aperture pear-shaped, 
decidedly channeled anteriorly with a feeble sinus in the outer lip near its 
summit. 

Type: Nematoma hokkaidoensis, new species (fig. 1). 

CURTITOMA, new genus. 

Shell short, stubby, ovoid. Postnuclear whorls strongly tabulatedly 
shouldered, but Avithout a keel at the angulation of the shoulder. Axial 
ribs very strong between the shoulder and periphery, evanescing on the 
base. The spiral sculpture consists of incised lines on the spire and threads 
on the columella. Aperture pear-shaped, decidedly channeled anteriorly, 
with a feeble sinus at the shoulder. 

Type: Curtitoma hecuba, new species (fig.. 3). 

VENUSTOMA, new genus. 

Shell small, varying in shape from ovate to broadly ovate. The first 
nuclear turn is smooth, the next shows the beginning of the spiral cords of 
the postnuclear sculpture. Postnuclear whorls with a roundly sloping 
shoulder, which extends over almost half of the turns and terminates in a 
well-marked angulation. The whorls are ornamented by well developed, 
sigmoid axial ribs and almost equally strong spiral cords, the junction of 
which produce rounded tubercles. The spiral sculpture of the shoulder is 
usually weaker and more crowded than that on the rest of the whorls. 
Suture moderately constricted. Periphery well rounded. Base moderately 
long, ornamented Hke the spire, but with the sculpture a little finer. 
Columella stout, marked by spiral cords and slender axial threads. Aperture 
pear-shaped, strongly channeled anteriorly, with a weak sinus at the shoulder 
on the outer Up. 

Type: Venustoma harucoa, new species (fig. 7). 

CANETOMA, new genus. 

Shell smaU, ovate. Nuclear whorls unknown; postnuclear whorls with a 
strong shoulder which extends over the posterior half of the turns; the 
anterior termination of the shoulder is marked by a spiral cord which 
renders the shell decidedly angulated here. The whorls are marked by 
well developed axial ribs and strong spiral cords that form rounded tubercles 
at their junction. In addition to this, there are finer spiral lirations and 
incremental fines that give the spaces between the axial ribs and spiral 
cords a fine reticulation, the whole producing a basket-Uke effect. Suture';


	$query = 'Venustoma harucoa';
	//$query = 'axial threads';
	
	$result = find_in_text($query, $text, true);
	
	print_r($result);


}

if (0)
{
	$json = '{
  "needle": "Angianthus micropoides",
  "haystack": "168 \nDistribution (Fig. 2): \nNullarbor Plain region. Common, \nEcology: \nOccurs on both clay and loam soils. Collectors’ notes include “Common on clayey \nsoils”, “Fine sandy loam over calcrete” and “In loam over limestone”. \nNote: \nl.A. conocephalus was originally described by Black (1929) as a variety of \nA. brachypappus. The var. conocephalus was considered to have a conical compound \nhead and var. brachypappus a cylindrical head. However the shape of the compound \nhead is quite variable. On the other hand both species exhibit distinct differences in habit \nand leaf morphology and usually pappus morphology. They are also allopatric. \nSelected Specimens Examined (5/23): \nWestern Australia — ApHn 1656, Forrest, 31.viii.1962 (PERTH); Chinnock 1151, 30 km S. of \nRawlinna, 19.ix.l973 (AD); George 8495, 30 miles NW. of Reid, 14.x. 1966 (PERTH). \nSouth Australia — Chinnock 1183, 15 km E. of Koonalda homestead, 21.ix.l973 (AD); Ising 1529, \nHughes, 8.ix.l920 (AD). \n8. Angianthus micropodioides (Benth.) Benth., FI. Austr. 3:565 (1867) {^micropo ides\'); \nGrieve & Blackall, W. Aust. Wildfls 812 (1975) {\'micropoides\'). — Phyllocalymma \nmicropodioides Benth., Enum. PI. Hueg. 62 (1837); Steetz in Lehm. PI. Preiss. 1:436 \n(1845). — Styloncerus micropodioides (Benth.) Kuntze, Rev. Generum PI. 367 (1891) \n{\'micropodes\'). Type: “Swan River. (Hiigel.).” Lectotype (here designated): Hugel s.n., \nSwan River, s. dat. (W). Isolectotype: K (see note 1 below). \nPhyllocalymma filaginoides Steetz in Lehm. PI. Preiss. 1:437 (1845); Steetz in \nWalper’s Repert. Bot. Syst. 6:229(1846). — Angianthus micropodioides filaginoides \nEwart & J. White, Proc. Roy. Soc. Viet. 22:92 (1909) {\'micropoides\'). Type: “In solo \narenoso — turfoso inter frutices ad fluvii Cygnorum ripam prope oppidulum Perth, \nmense Januario 1839. Herb. Preiss. No. 37.” Lectotype (here designated): Preiss 37, In \nNova Hollandia, (Swan-River Colonia) in solo arenoso turfoso inter frutices ad flumis \nCygnorum ripam leg. cl. Preiss, s. dat. (MEL 541603). Isolectotypes: LD, MEL 541604, \nMEL 541605 (ex herb. O. W. Sonder), MEL 583143 (ex herb O. W. Sonder), S, GH (ex \nherb. Klatt), (see p.l52). \nAnnual herb. Major axes ascending to erect, 4-15 cm long, hairy; stem sometimes \nsimple to c. 10 cm high, but usually forming major branches at basal and/or upper \nnodes. Leaves alternate, ± linear or lanceolate, 0.5-1. 5(2.8) cm long, 0.05-0.1 cm wide, \ndistinctly mucronate, variably hairy. Compound heads ± depressed ovoid to broadly \ndepressed ovoid, 0.4-0.6 cm long, 0.4-0.5 cm diam., axillary or terminal; bracts \nsubtending compound heads forming a conspicuous involucre exceeding the length of the \nhead, ofc. 10 leaf-like bracts, ± lanceolate to ± ovoid, 0.5-1. 5 cmlong, c. 0.1 cm wide, \nmucronate, hairy; general receptacle a small convex axis. Capitula c. 10-30 per \ncompound head; capitulum-subtending bracts 1, ± oblong or ovate, 2. 1-2.8 mm long, \n0.8-1. 3(1. 5) mm wide, the midrib variably hairy toward the apex. Capitular bracts with \nthe two concave ones 2. 4-3.1 mm long, the midrib hairy; flat bracts 2, obovate, ± \nabruptly attenuated in the lower Vi, 2. 4-3.1 mm long, (0.75)0.9-1.25 mm wide, the \nmidrib usually variably hairy toward the apex, rarely glabrous. Florets 2; corolla 5-lobed, \nthe tube tapering gradually towards the base in immature florets, a more abrupt taper in \nthe lower V 3 of mature florets which have variably swollen bases, 1.4-1. 9 mm long, \nc. 0.5 mm diam. Achenes ± obovoid, 0.8-1 mm long, 0. 5-0.6 mm diam., pubescent. \nPappus of 5 or 6 jagged scales fused at the base, each sc^e terminating in a single smooth \nor minutely barbellate bristle, the total pappus c. ‘73-^3 the length of the corolla \ntube. Fig. 3k. \nDistribution (Fig. 2): \nWestern Australia, particularly in the South West Drainage Division (Mulcahy & \nBettenay, 1972), between latitudes c.28°30\'S and 32°S and west of longitude c.l22°E. \nLocally common."
}';

	$doc = json_decode($json);
	
	
	if (json_last_error() != JSON_ERROR_NONE)
	{
		echo json_last_error_msg() . "\n";
	}
	
	//print_r($doc);
	

	$result = find_in_text($doc->needle, $doc->haystack, false, 2);
	
	print_r($result);


}

if (0)
{
	$needle = "Koonalda";
	$haystack = "\nl.A. conocephalus was originally described by Black (1929) as a variety of \nA. brachypappus. The var. conocephalus was considered to have a conical compound \nhead and var. brachypappus a cylindrical head. However the shape of the compound \nhead is quite variable. On the other hand both species exhibit distinct differences in habit \nand leaf morphology and usually pappus morphology. They are also allopatric. \nSelected Specimens Examined (5/23): \nWestern Australia — ApHn 1656, Forrest, 31.viii.1962 (PERTH); Chinnock 1151, 30 km S. of \nRawlinna, 19.ix.l973 (AD); George 8495, 30 miles NW. of Reid, 14.x. 1966 (PERTH). \nSouth Australia — Chinnock 1183, 15 km E. of Koonalda homestead, 21.ix.l973 (AD)";
	
$haystack = " “In loam over limestone”. \nNote: \nl.A. conocephalus was originally described by Black (1929) as a variety of \nA. brachypappus. The var. conocephalus was considered to have a conical compound \nhead and var. brachypappus a cylindrical head. However the shape of the compound \nhead is quite variable. On the other hand both species exhibit distinct differences in habit \nand leaf morphology and usually pappus morphology. They are also allopatric. \nSelected Specimens Examined (5/23): \nWestern Australia — ApHn 1656, Forrest, 31.viii.1962 (PERTH); Chinnock 1151, 30 km S. of \nRawlinna, 19.ix.l973 (AD); George 8495, 30 miles NW. of Reid, 14.x. 1966 (PERTH). \nSouth Australia — Chinnock 1183, 15 km E. of Koonalda homestead, 21.ix.l973 (AD); ";	


$needle = "Abascantus";
$haystack = " phaerico , convexe , intus bisinuato , concavo , apice sub torto. 

Abascantus sannio Schauf. — Convexus, obovatus, nitidus, 
 disperse-pilosus , cribrato-punctatus , abdomine supra glabro , pilosulo; 
 antennarum articulo primo elongato , ultimum articulum fere longitu- 
";

	echo mb_strlen($haystack) . "\n";
	
	$result = find_in_text($needle, $haystack, true, 2);
	print_r($result);


}

// need to understand why JSON \u works for this string, but not original encoding
if (0)
{





	$needle = "Eccoptura";
	$haystack = "60 



den ScblJifen braunlich. Pronotum lichtbraun,die Schwielen lichter 

geiblich, Vorderecken diinkler, Ilinterrand scbinal schwarzbraun 

gfisâumt. Meso- iind Metanotum dimkelbraun, die Mittelb'nie bis 

zum Scuteliuin und die vertieften Partien zwischen Scutum und 

Praescutum lichtgelb. Hinterleib mit Ausnahme der briiunlichen 

Spitze und die ganze Unterseite ockergelb. Fûbler im basalen Drittel 

ockergelb, nach der Spitze zu braunlich. Taster briiunlicb. Beine 

ockergelb, an den Knien, Schienenspitzen und Fussspitzen braun- 

lich. Schwanzborsten ockergelb. 



Flûgel hyalin, nur der Gostalstreifen schwach geiblich. Nervatur 

gelb, die Haupladern an der Wurzel verstfirkt und braun. 



Ktirperlânge 16 mill., Flûgelspannung 49 mill. 



Habitat: 1 $ Bezirk Tkwibul, Gouv. Kutaisk., 29-VII-95, leg. 

KusLJAKOV (Mus. St-Petersburg). 



3. Ag-netina brevipennis nov. sp. — Kopf ockergelb, zwi- 

schen den Punktaugen mit einem schwarzen, etwas verwaschenen 

Makel, der vorn vor den Stirnschwielen nach aussen sich erweitert. 

Pronotum dunkel rôtlichbraun mit schwarzbraunen Vorder- und 

Hinterrande. Meso- und Metanotum etwas dunkler aïs das Prono- 

tum. Hinterleib und die Unterseite gelbbraun. Fiihler im unteren 

Drittel ockergelb, sonst braun. Die aufîallend dtïnnen Taster braun. 

Beine gelbbraun, die oberen Kanten etwas dunkler, der Knierend 

der Schenkel und die ersten 2 Fiissglieder schwarzbraun. Das kleine 

Stuck von Schwanzborste, welches ûbrig geblieben ist, gelbijraun. 



Flûgel briiunlicb getrûbt, Nervatur briiunlich, Costa bis ûber die 

Flûgelmilte ganz blass geiblich. Radius dagegen besonders gegen 

die Wurzel zu verstârkt, dunkelbraun und stark vorlretend, Sub- 

costa an der humeralen Querader knotig verdickt. 



Kôrperlange 17 mill.; Flûgelspannung 27 mill* 



Habitat : 1 ÇKischl. Schut, Buchara, leg. Bartsghewski (Mus. 

Petersburg). 



Gen. ECGOPTURA nov. gen. 



• 



Kopf kurz, fast halbkreisfôrmig, mit stark konvergierenden Seiten 

und stumpfen, bis abgerundeten Yorderwinkeln des Kopfschildes. 

Augen ziemlich gross und gewôlbt. Auch die hinteren Punktaugen 

recht gross. aber das vordere viel kleiner; sie sind in ein schwach 

gleichartiges Dreieck gestellt und der gegenseitige Abstand der 

hinteren ist deutlich grôsser als die Enlfernung vom Innenrande 

der Augen. Die Stirnschwielen kaum grôsser als die hinteren 

Punktaugen. von denselben gleich wie vom Innenrande der Augen 

entfernt.

";
	
	//$result = find_in_text($needle, $haystack, true, 2);
	
	$result = find_in_text_simple($needle, $haystack, true, 2);
	
	print_r($result);


}

if (0)
{
	$needle = "Cnemopsilus";
	$haystack = "190 \n\nSILPHIDES ET LIODIDES NOUVEAUX \n\npar 11. G. Portevin \n\nCHOLEVINI \n\nGNEMOPSILUS gen. nov. \n\nMesosternum haud carinatum. Elytra transversim strigosa.Tibiee \n vix spinosse. rf Femora anleriores infra dentata, tarsi anteriores et \n intermedii 4^' primisarticulis dilatatis. \n\nSe rapproche du genre Catops, mais s'en distingue par ses tibias \n garnis seulement d'épines rares et très courtes, ses élytres striolés \n en travers, et les caractères du (^ . \n\nC. femoratus nov. sp. — Longe ovalis, niger, parum nitidum, \n tenuissime griseo luteo pubescens, depressus, habitu corporis Pto- \n maphagi generis. Gaput leviter punctatum, antennis prothoracis \n\nbasi attingentibus, basi ru- \n ^^^ j,^Ç^ bra, articule ultimobreviter \n\novato et obtuse acuminato. \n Pronotum transversum, la- \n teribus antice regulariter \n rotundatiSjtenuiter et dense \n punctatum, in medio levis- \n sime rotundato foveolatum; \n angulis posticis paulo pro- \n ductis, acutis. Elytra regu- \n lariter postice attenuata, \n parum et oblifjue truncata \n tenuiter et dense strigosa, \n haud striata. Pedes nigri, \n femoribus clavatis, tarsis \n anterioribus et intermediis dilutioribus ; abdomen segmenti ultimo \n rubro. ç^ Femora anteriores dente brève; tibia3 anteriores \n fortiter curvatis ad apicem dilatatis, intermedii curvatis, posteriores \n rectis; tarsi anteriores et intermedii dilatati, longe et dense luteo \n pubescentes. — Long. 3,7 mill. \n\nI cf. Nouvelle-Zélande (Deut. Ent. Nat. Muséum). \n\nII est impossible de rapporter l'espèce ci-dessus à l'une de celles \n créées par Broun (Manual New. Zeol. Col.). D'ailleurs, d'une façon \n générale, les descriptions de cet auteur sont incomplètes, des carac- \n\n";
	
	$result = find_in_text_simple($needle, $haystack, true, 2);
	
	print_r($result);
}

if (0)
{
	$needle = "Polyporandra";
	$haystack = "ICACINE.-E 125 \n\nraiQUELiA cELEBicA Bl. Rmhph. IV, p. 37 et Mas. hot. I , p. 42, fìg. Vili. \n\n— Miq. Fi. Ind. bat. 1 , pars I, p. 798. — Baili. I. c. \n\nAbita. — Nella Penisola N. E. di Selebes presso Tondano, alle sorgenti termali \n di Tartaran (non vidi). \n\nGen. XI L SARCOSTIGMA Wight et Aro. \n\nBenth. et Hook. Gen. plant. I , p. 354. — Baili, in DC. Prodi-. XV II , p. 15. \n\nsarcostigma HORSPiELDii R. Brown in Horsf. PI. Jav. rar. p. 241 , (ab. 47. \n\n— Miq. FI. Ind. bat. I, pars ì , p. 795. — Baili. I. e. \n\nAbita. — In Giava nella provincia di Blambangan e presso il villaggio Patijttan \n (non vidi). \n\nGen. XIII. POLYPOR ANDRA Dece. \n\nFlores dioici (vel interdum monoici?) cymosi. FI. 6; calyx cupularis vix 6-denti- \n culatus; petala 6 basi brevissime adnata, valvata, carnosa, apice in alabastro longe \n appendiculato-inflexa. Stamina 6, filamento nullo; antheraa crassa globosae sessiles \n extus undique alveolataa, alveolis, operculo membranaceo deciduo clausis, pollini- \n feris; ovarii rudimentum parvum conicum. FI. 9; calyx profunde 5-dentatus: sepala 6 \n alternantia pilosa basi coalita; ovarium pilosum globosum uniloculare; stigma latis- \n simum discoideum medio umbonatum ; ovula 2 ex apice loculi pendula. Fructus . . . . \n — Frutex scandens. \n\npolyporandra scandens sp. ii. — Frutex dioicus, alte scandens; ramuli \n subteretes pubescentes; folia opposita, petiolata, ovata, vel ovali-elliptica, integra, \n acutiuscula vel breviter acuminata, obtuse mucronulata, superne glabra, subtus pu- \n bescentia, penninervia, costulis utrinque 4-6. Paniculaa axillares; masculae foemineis \n multo longiores; sepala et petala adpresse et minutissime pilosa. (Tab. VII). \n\nDescrizione. — La pianta sembra dioica; quantunque fra gli esemplari femminei, \n ve ne siano alcuni con dei fiori maschi già trapassati, per cui potrebbe anche darsi che \n talvolta fosse monoica. E un frutice altamente scandente a fusto legnoso e cirrifero; \n i cirri però non sono molto frequenti; i ramoscelli sono quasi tereti, pubescenti; le \n foglie sono opposte, o quasi, e lasciano una larga cicatrice sui rami al punto della \n loro inserzione, sono con picciolo subterete , di sopra strettamente canaliculato , \n lungo 8-12 mill. ; il lembo è ovale-ellittico, lungo 9-14 cent., largo 45-70 mill., col \n margine intero, la base è talvolta un poco obliqua e piuttosto acuta come l'apice, il \n quale è anche non di rado un poco acuminato, e terminato da un cortissimo mucrone \n ottuso; la superfìcie superiore è glabra, la inferiore è pubescente, ambedue verdi \n pallide ed assai fortemente penninervie, con 4-6 nervi per lato; i nervi basilari sono \n meno forti ed uno di essi è assai sottile e quasi marginante. Infiorazioni ascellari, quelle \n\n";

//	$result = find_in_text_simple($needle, $haystack, true, 3);
	$result = find_in_text($needle, $haystack, true, 3);
	
	print_r($result);

}

if (0)
{
	$needle = "Begonia jamilahanuana";
	$haystack = "Eight new Begonia from Sarawak \n271 \nFig. 6. Begonia hirtitepala S. Julia & Kiew. A, B. Habit; C. Hairy upper leaf surface. D. \nInflorescence. E. Male inflorescence. F, G. Female flower. H. Female flower and fruit. I. \nCross-section of ovary. From the type SFC6614. (Photos: A-H, S. Julia; I, C.Y. Ling) \n7. Begonia jamilahanuiana S. Julia, sp. nov. (SQCtion Petermannia) \nThis species resembles Begonia malachosticta Sands in its habit and narrowly ovate \nleaves that are spotted in the adult plant but it differs in the spots being white (vs. bright \npink in B. malachosticta), male flowers with 2 tepals (vs. 4 tepals), fewer stamens 27- \n32 (vs. stamens 75-85 in 5. malachosticta) and smaller capsules 11-17 x 11-15 mm";

	$needle = "Anathallis funerea";
	$haystack = "Although Luer & Toscano-de-Brito (2002) \npointed out that the holotype was deposited at the \nherbarium of the State University of Feira de \nSantana (HUEFS) we are unable to fmd it there, and \naccording to the staff of this herbarium apparently \nit has never been deposited there. Despite this fact \nthe description and the illustration show that the \nspecies has the characteristics of Anathallis. \nAnathallis funerea (Barb.Rodr.) F.Barros & \nBarberena, comb. nov. Lepanthes funerea \nBarb.Rodr., Vellosia,ed. 2, 1: 1 18. 1891. Pleurothallis \nfunerea (Barb.Rodr.) Cogn. in Martius, Eichler & \nUrban, Fl. bras. 3(4): 567. 1896. Specklinia funerea \n(Barb.Rodr.) Luer, Monogr. Syst. Bot. Missouri Bot.\nGard. 95: 260. 2004. Panmorphia funerea \n(Barb.Rodr.) Luer, Monogr. Syst. Bot. Missouri Bot.\nGard. 105: 156. 2006. Type: BRAZIL. AMAZONAS: \nrio Yauapery, J.B. Rodrigues s.n. (holotype RB, \ndestroyed); lectotype, here designated, fig. D, tab. \n200, vol. 3 (J. Barbosa Rodrigues, Iconographie \ndes Orchidées du Brésil, reproduced in Sprunger \n(1996, p.258)). \nAnathallis globifera (Pabst) F.Barros & Barberena, \ncomb. nov. Pleurothallis globifera Pabst, Arch. \nJard. Bot. Rio de Janeiro 14: 13. 1956. Type: BRAZIL. \nSANTA CATARINA: Ilha de Santa Catarina, \nSertão da Lagoa, 6.IV.1951, fl., J.A. Rohr 2126 \n(holotype HB!). \nAnathallis laciniata (Barb.Rodr.) F.Barros & \nBarberena, comb. nov. Pleurothallis laciniata \nBarb.Rodr., Gen. Spec. Orchid. 1: 14. 1877. \nLepanthes laciniata (Barb.Rodr.) Barb.Rodr., Gen. \nSpec. Orchid. 2: 68. 1881. Specklinia laciniata \n(Barb.Rodr.) Luer, Monogr. Syst. Bot. Missouri Bot.\nGard. 95: 261. 2004. Panmorphia laciniata \n(Barb.Rodr.) Luer, Monogr. Syst. Bot. Missouri Bot.\nGard. 105: 177. 2006. Type: BRAZIL. MINAS \nGERAIS: Poços de Caldas, 1.1877,7.5. Rodrigues \ns.n. (holotype RB, destroyed); lectotype, here \ndesignated, fig. A, tab. 133, vol. 3 (J. Barbosa \nRodrigues, Iconographie des Orchidées du Brésil, \nreproduced in Sprunger ( 1 996, p. 191)). \nAnathallis lobiserrata (Barb.Rodr.) F.Barros & \nBarberena, comb. nov. Lepanthes lobiserrata \nBarb.Rodr., Gen. Spec. Orchid. 2: 63. 1881. \nRodriguésia 61 ( 1 ): 1 27 - 131.2010 \nSciELO/JBRJ \n13 14 15 16 17 18 19\n";


	//$needle = "Aglaomorpha rigidula";
	//$haystack = "New combinations in Aglaomorpha \n153 \nAglaomorpha rigidula (Sw.) Hovenkamp & S.Linds., comb. nov. - Polypodium \nrigidulum Sw., J. Bot. (Schrader) 1800(2): 26 (1801). - Goniophlebium rigidulum \n(Sw.) T.Moore, Index Fil.: 396 (1862). - Drynaria rigidula (Sw.) Bedd., Ferns Brit. \nIndia 3: pi. 314 (1869). - TYPE: Java, Thunberg s.n. (lectotype UPS [Cat. No. 24565], \ndesignated here). \nNote. Roos (1986) chose a Thunberg specimen from Java at S to be the lectotype (and \nthe same specimen was cited in Flora Malesiana (Hovenkamp & Roos, 1998)) but we \nhave been unable to trace this specimen. \nDistribution. SE Asia to the Pacific islands and tropical Australia. \nAglaomorpha sagitta (Christ) Hovenkamp & S.Linds., comb. nov. - Polypodium \nsagittum Christ, Bull. Herb. Boissier 6: 199 (1898) [as ‘sagitta’]. - Christiopteris \nsagitta (Christ) Copel. in Perkins, Fragm. FI. Philipp.: 188 (1905). - Selliguea sagitta \n(Christ) Fraser-Jenk., Taxon. Revis. Indian Subcontinental Pteridophytes: 45 (2008). \n- Drynaria sagitta (Christ) Christenh., Phytotaxa 230(3): 299 (2015) [as ‘ sagittata’]. \n- TYPE: Philippines, Luzon, La Trinidad. Loher s.n. (lectotype P [P01545178], \ndesignated by Hennipman & Hetterscheid (1984)). \nDistribution. Philippines (Luzon, Mindanao). \nAglaomorpha sparsisora (Desv.) Hovenkamp & S.Linds., comb. nov. - Polypodium \nsparsisorum Desv., Ges. Naturf. Freunde Berlin Mag. Neuesten Entdeck. Gesammten \nNaturk. 5: 315 (1811). -Drynaria sparsisora (Desv.) T.Moore, Index Fil.: 348 (1862). \n- TYPE: Papua New Guinea, Central Province, Cloudy Bay, 16 Feb. 2007, Johns \n11056 (neotype SING [SING0195508] designated here; isoneotypes BRI (n.v.), BRIT \n(n.v.), K (n.v.), L (n.v.), LAE (n.v.), UPNG (n.v.)). \nNote. In the protologue Desvaux (1811) gave the distribution of the species as ‘Habitat \nin America?’, clearly expressing doubt as to where the species was from. The species \nis only known in tropical Asia and Australasia, not in the Americas. We have been \nunable to trace any original material. \nDistribution. Thailand, Cambodia, Malesia and tropical Australia. \nAglaomorpha tricuspis (Hook.) Hovenkamp & S.Linds., comb. nov. - Acrostichum \ntricuspe Hook., Sp. Fil. 5: 272, t. 304 (1864). - Gymnopteris tricuspis (Hook.) Bedd., \nFerns Brit. India 1: pi. 53 (1865). - Cheiropleuria tricuspe (Hook.) J.Sm., Hist. Fil.: \n139 (1875) [as “bicuspe”]. - Leptochilus tricuspis (Hook.) C.Chr., Index Filic., fasc. \n7: 388 (1906). - Christiopteris tricuspis (Hook.) Christ, J. Bot. (Morot), ser. 2, 1: 273 \n\n";

	$result = find_in_text($needle, $haystack, true, 2);
	
	print_r($result);

}


?>
