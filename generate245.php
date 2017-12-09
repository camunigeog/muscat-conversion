<?php

# Class to generate the complex 245 (Title and statement of responsibility) field; see: http://www.loc.gov/marc/bibliographic/bd245.html
class generate245
{
	# Constructor
	public function __construct ($marcConversion, $xml, $authorsFields, $languageMode = 'default')
	{
		# Create a class property handle to the parent class
		$this->marcConversion = $marcConversion;
		
		# Create a handle to the XML
		$this->xml = $xml;
		
		# Create a handle to the authors fields
		$this->authorsFields = $authorsFields;
		
		# Create a handle to the language mode; transliteration has to be done at a per-subfield level, because within a subfield there can be e.g. 'Name, editor' where only the 'Name' part would be transliterated
		$this->languageMode = $languageMode;
		
		# Define the Statement of Responsibility delimiter
		$this->muscatSorDelimiter = ' / ';	// Decided not to tolerate any cases with space not present after
		
		# Define unicode symbols
		$this->doubleDagger = chr(0xe2).chr(0x80).chr(0xa1);
		
	}
	
	
	# Main
	public function main (&$error = false)
	{
		# Determine the record type
		$this->recordType = $this->recordType ();
		
		# Determine the main record type to use as the XPath, i.e. /art, /doc, or /ser
		$this->mainRecordTypePrefix = $this->recordType;
		if ($isArtRecord = (in_array ($this->recordType, array ('/art/in', '/art/j')))) {
			$this->mainRecordTypePrefix = '/art';
		}
		
		# Obtain the title, by looking at *ser/*tg/*t OR *doc/*tg/*t OR *art/*tg/*t
		$this->t = $this->marcConversion->xPathValue ($this->xml, "{$this->mainRecordTypePrefix}/tg/t");
		
		# Transliterate title (used for $a and possible $b) if required
		if ($this->languageMode != 'default') {
			
			# Define the lpt field XPath; this must the one directly associated with the title, e.g. /art/tg/t should not use /art/in/tg/lpt but /art/in/tg/t should; e.g. /records/210651/, /records/202321/, /records/1104/ (test #164)
			$lptFieldXpath = "{$this->mainRecordTypePrefix}/tg/lpt";
			
			# Do the transliteration; e.g. /records/210651/ (test #165)
			$lpt = $this->marcConversion->xPathValue ($this->xml, $lptFieldXpath);	// Languages of parallel title, e.g. "Russian = English"
			$this->t = $this->marcConversion->transliteration->transliterateLocLatinToCyrillic ($this->t, $lpt, $error, $nonTransliterable /* passed back by reference */);	// (test #49)
			
			# End if the transliteration has determined that the string is not actually intended for transliteration, e.g. [Titles fully in brackets like this]; e.g. /records/31750/
			if ($nonTransliterable) {return false;}
		}
		
		# Determine first and second indicator
		$firstIndicator = $this->firstIndicator ();
		$secondIndicator = $this->secondIndicator ();
		
		# Determine the title
		$title = $this->title ();
		
		# Determine the Statement of Responsibility
		$statementOfResponsibility = $this->statementOfResponsibility ($this->mainRecordTypePrefix, $this->t);
		
		# Compile the value
		$value  = $firstIndicator;
		$value .= $secondIndicator;
		$value .= ' ';
		$value .= $title;
		$value .= $statementOfResponsibility;
		
		# Ensure the value ends with a dot (even if other punctuation is already present); e.g. /records/137684/ , /records/178352/ avoids two dots (test #177); also /records/1058/ which ends with ) so gets ). (test #178)
		$value = $this->marcConversion->macro_dotEnd ($value, $extendedCharacterList = false);
		
		# Return the value
		return $value;
	}
	
	
	# First indicator
	private function firstIndicator ()
	{
		# Does this MARC record contain a 1XX field?; e.g. /records/210651/ (test #166), /records/1102/ (test #167), /records/1134/ (test #168)
		return ($this->recordHas1xxField ($this->authorsFields) ? '1' : '0');
	}
	
	
	# Function to determine if the MARC record contains a 1XX field; e.g. /records/210651/ (test #166), /records/1102/ (test #167), /records/1134/ (test #168)
	private function recordHas1xxField ($authorsFields)
	{
		# Determine if any 1XX field has a value
		foreach ($authorsFields['default'] as $marcCode => $value) {
			if (preg_match ('/^1/', $marcCode)) {	// Consider only 1XX fields
				if (strlen ($value)) {
					return true;
				}
			}
		}
		
		# Not found
		return false;
	}
	
	
	# Second indicator
	private function secondIndicator ()
	{
		# Does the *t start with a leading article? E.g. /records/1110/ (test #169), /records/1134/ (test #170), /records/103693/ (test #171)
		$nfCountLanguage = ($this->languageMode == 'default' ? false : $this->languageMode);	// Language mode relates to transliteration; languages like German should still have nfCount but will have 'default' language transliteration mode
		$leadingArticleCharacterCount = $this->marcConversion->macro_nfCount ($this->t, $nfCountLanguage, $errorString_ignored, $this->xml);
		
		# Return the leading articles count
		return $leadingArticleCharacterCount;
	}
	
	
	# Helper function to determine the record type
	#!#C Copied from generate008 class
	private function recordType ()
	{
		# Determine the record type, used by subroutines
		$recordTypes = array (
			'/art/in',
			'/art/j',
			'/doc',
			'/ser',
		);
		foreach ($recordTypes as $recordType) {
			if ($this->marcConversion->xPathValue ($this->xml, $recordType)) {
				return $recordType;	// Match found
			}
		}
		
		# Not found
		return NULL;
	}
	
	
	# Title
	private function title ()
	{
		# Start a title
		$title = '';
		
		# Ensure the title is not empty
		$t = $this->t;
		if (!strlen ($t)) {$t = '[No title]';}	// No actual cases left so cannot test (found using "SELECT id, EXTRACTVALUE(xml,'//tg/t') AS tValue FROM catalogue_xml HAVING LENGTH(tValue) = 0;") but logic left in as catch
		if ($t == '-') {$t = '[No title]';}	// E.g. No actual cases left so cannot test; only /records/182768/ which is an *j/*tg/ which is not relevant
		
		# If there is a / in the title explicitly, use that and discard all people groups; e.g. /records/10503/ (test #439); not triggered by </em> (test #440)
		if (substr_count ($t, $this->muscatSorDelimiter)) {
			list ($t, $statementOfResponsibility) = explode ($this->muscatSorDelimiter, $this->t, 2);
			$t = trim ($t);
		}
		
		# For potential splitting into $a and $b, firstly determine whether a colon or space-semicolon-space comes first, e.g. /records/135894/ (test #508)
		$delimiter = false;
		$delimiters = array (
			':'		=> ' :',	// E.g. /records/1119/ (test #172)
			' ; '	=> ' ;',	// E.g. /records/139981/ (test #506), /records/1156/ (test #507)
		);
		if (substr_count ($t, ':') || substr_count ($t, ' ; ')) {
			$length = strlen ($t);
			for ($i = 0; $i < $length; $i++) {
				foreach ($delimiters as $testDelimiter => $replacement) {	// Cannot be both
					if (substr ($t, $i, strlen ($testDelimiter)) == $testDelimiter) {
						$delimiter = $testDelimiter;
						break 2;
					}
				}
			}
		}
		
		# Does the record contain a *form? If so, construct $h, using lower-case, e.g. /records/12359/ (test #579) (except upper-case types like DVD)
		$h = false;		// No $h if no form, e.g. /records/9542/ (test #577)
		if ($forms = $this->marcConversion->xPathValues ($this->xml, '//form[%i]', false)) {
			foreach ($forms as $index => $form) {
				if ($form != mb_strtoupper ($form)) {	// Maintain case for upper-case *form types like DVD; e.g. /records/160682/ (test #580)
					$forms[$index] = mb_strtolower ($form);
				}
			}
			$h = $this->doubleDagger . 'h[' . implode ('; ', $forms) . ']';		// If multiple *form values, separate using semicolon in same square brackets, e.g. /records/181410/ (test #578)
		}
		
		# Does the *t include the delimiter? E.g. /records/1119/ (test #172)
		if ($delimiter) {
			
			#!# Need to check spacing rules here and added trimming; e.g. see /records/12359/
			
			# Add all text before delimiter; e.g. /records/1119/ (test #172)
			$titleComponents = explode ($delimiter, $t, 2);
			$title .= $this->doubleDagger . 'a' . trim ($titleComponents[0]);
			
			# If there is a *form, Add to 245 field'; "It follows the title proper ... and precedes the remainder of the title" as per spec at http://www.loc.gov/marc/bibliographic/bd245.html ; e.g. /records/12359/ (test #173)
			$title .= $h;
			
			# Add all text after delimiter; e.g. /records/1119/ (test #172), /records/139981/ (test #506)
			$title .= $delimiters[$delimiter] . $this->doubleDagger . 'b' . trim ($titleComponents[1]);
			
		} else {
			
			# Add title; e.g. /records/1000/ (test #174)
			$title .= $this->doubleDagger . 'a' . $t;
			
			# If there is a *form, Add to 245 field; e.g. /records/9543/, /records/1186/ (test #175); also transliterated record example: /records/9543/ (test #176)
			$title .= $h;
		}
		
		# Return the title
		return $title;
	}
	
	
	# Statement of Responsibility; this is also supported for *ser, e.g. /records/1028/ (test #179)
	public function statementOfResponsibility ($pathPrefix, $title)
	{
		# Start a list of non-empty parts of the Statement of Responsibility, which will be grouped by each *ag
		$peopleGroups = array ();
		
		# Look at first or only *doc/*ag/*a OR *art/*ag/*a ; e.g. /records/1121/ (test #181), /records/1135/ (test #182)
		# THEN: Is there another *a in the parent  *doc/*ag OR *art/*ag which has not already been included in this 245 field? E.g. /records/1121/ (test #183), /records/1135/ (test #184), /records/181939/ (test #185)
		# THEN: Is there another *ag in the parent  *doc OR *art, whose *a fields have not already been included in this 245 field?
		$agIndex = 1;
		while ($this->marcConversion->xPathValue ($this->xml, "{$pathPrefix}/ag[$agIndex]")) {		// Check if *ag container exists
			
			# Start a list of non-empty authors for this *ag
			$authorsThisAg = array ();
			
			# Loop through each *a (author) in this *ag (author group); e.g. /records/1121/ (test #183), /records/1135/ (test #184), /records/181939/ (test #185)
			$aIndex = 1;	// XPaths are indexed from 1, not 0
			while ($string = $this->classifyNdField ("{$pathPrefix}/ag[$agIndex]/a[{$aIndex}]")) {
				
				# If *n1 = '-' (only), this should (presumably) not generate an entry; this is an addition to the spreadsheet spec; e.g. /records/166294/ (test #193), /records/115773/ (test #194)
				if ($string == '-') {
					$aIndex++;
					continue;
				}
				
				# Register this author value
				$authorsThisAg[] = ($this->languageMode == 'default' ? $string : $this->marcConversion->transliteration->transliterateLocLatinToCyrillic ($string, false));
				
				# Next *a
				$aIndex++;
			}
			
			# Skip if no authors
			if (!$authorsThisAg) {
				$agIndex++;
				continue;
			}
			
			# Separate multiple authors with a comma-space; e.g. /records/1135/ (test #186)
			$authorsThisAg = implode (', ', $authorsThisAg);
			
			# Is there a *ad in the parent  *doc/*ag OR *art/*ag? E.g. /records/149106/ has one (test #191); /records/162152/ has multiple (test #192); /records/149107/ has implied ordering of 1+2 but this is not feasible to generalise
			# Does the *ad have the value '-'?
			if ($ad = $this->marcConversion->xPathValues ($this->xml, "{$pathPrefix}/ag[$agIndex]/ad[%i]")) {
				$isSingleDash = (count ($ad) == 1 && $ad[1] == '-');	// NB No actual examples of any *ad = '-' across whole catalogue, so no testcase
				if (!$isSingleDash) {
					$authorsThisAg .= ', ' . implode (', ', $ad);	// Does not get transliterated, e.g. 'eds.'
				}
			}
			
			# Register the (now-confirmed non-empty) authors for this *ag
			$peopleGroups[] = $authorsThisAg;
			
			# Next *ag
			$agIndex++;
		}
		
		# Does the record contain at least one *e?; e.g. /records/2930/ (test #195)
		$eIndex = 1;
		while ($this->marcConversion->xPathValue ($this->xml, "{$pathPrefix}/e[$eIndex]")) {		// Check if *e container exists
			
			# Add to 245 field: ; <*e/*role>
			$peopleGroups[] = $this->roleAndSiblings ("{$pathPrefix}/e[$eIndex]");
			
			# Next e
			$eIndex++;
		}
		
		# If there is a / in the title explicitly, use that (e.g. /records/10503/ (test #439)), and discard all people groups (e.g. /records/2683/ (test #549)); not triggered by </em> /records/1131/ (test #440)
		if (substr_count ($title, $this->muscatSorDelimiter)) {
			list ($t, $statementOfResponsibility) = explode ($this->muscatSorDelimiter, $title, 2);
			$peopleGroups = array (trim ($statementOfResponsibility));
		}
		
		# End if no author groups resulting in output; e.g. /records/166294/ (test #193), /records/115773/ (test #194), /records/2930/ (test #195), /records/145630/ (test #196)
		if (!$peopleGroups) {
			return false;
		}
		
		# Start the Statement of Responsibility with /$c ; e.g. /records/1159/ has a SoR (test #180)
		# Separate multiple author groups with a semicolon-space; e.g. /records/134805/ (test #187), /records/131672/ (test #190)
		$statementOfResponsibility = ' /' . "{$this->doubleDagger}c" . implode (' ; ', $peopleGroups);
		
		# Return the Statement of Responsibility
		return $statementOfResponsibility;
	}
	
	
	# Function to deal with a role and siblings; NB this is also used directly by the generate250b macro; e.g. /records/1844/ (test #197), and /records/2295/ which has multiple *e (test #198)
	# NB There are no cases in the data of 250 (which uses *ee) being in Russian, as verified by: `SELECT *  FROM catalogue_processed WHERE field LIKE 'n%' AND xPath LIKE '%/ee%' AND recordLanguage = 'Russian';`
	public function roleAndSiblings ($path)
	{
		# Obtain the role value, or end if none; no examples so no testcase; *role is not subject to transliteration, e.g. /records/1844/ (test #500)
		if (!$role = $this->marcConversion->xPathValue ($this->xml, $path . '/role')) {
			return false;
		}
		
		# Lower-case the first letter, to avoid Bibcheck error, "245: Edited should not be capitalised at start of subfield $c.", e.g. /records/193443/ (test #561)
		$role = lcfirst ($role);
		
		# Loop through each *a (author) in this *e/*ee; e.g. /records/1844/ (test #197)
		$subValues = array ();
		$nIndex = 1;	// XPaths are indexed from 1, not 0
		while ($string = $this->classifyNdField ($path . "/n[$nIndex]")) {
			$subValues[] = ($this->languageMode == 'default' ? $string : $this->marcConversion->transliteration->transliterateLocLatinToCyrillic ($string, false));	// e.g. /records/1844/ (test #50)
			
			# Next
			$nIndex++;
		}
		
		# Compile the entry; e.g. /records/1639/ (test #199) and /records/3876/ (test #200) which have multiple
		$result = $role . ($subValues ? ' ' . application::commaAndListing ($subValues) : '');	// No space if standalone role, e.g. /records/204088/ (test #562)
		
		# Return the value
		return $result;
	}
	
	
	# Classify *nd Field
	private function classifyNdField ($pathPrefix)
	{
		# Start the string
		$string = '';
		
		# Obtain the n1/n2/nd values; e.g. /records/1201/ (test #201)
		$n1 = $this->marcConversion->xPathValue ($this->xml, $pathPrefix . '/n1');
		$n2 = $this->marcConversion->xPathValue ($this->xml, $pathPrefix . '/n2');
		$nd = $this->marcConversion->xPathValue ($this->xml, $pathPrefix . '/nd');
		
		# Initials should not be spaced out for 245; e.g. /records/1135/ (test #202)
		# See: "When adjacent initials appear in a title separated or not separated by periods, no spaces are recorded between the letters or periods." "One space is used between preceding and succeeding initials if an abbreviation consists of more than a single letter." at https://www.loc.gov/marc/bibliographic/bd245.html
		if (strlen ($n2)) {
			$n2 = $this->unspaceOutInitials ($n2);
		}
		
		# Does the *a or *n contain a *nd?
		if ($nd) {
			
			# If present, strip out leading '\v' and trailing '\n'; e.g. see /records/118086/ (test #204)
			$nd = strip_tags ($nd);		// \v and \n have been converted to HTML italic tags in the catalogue_processed stage
			
			# *nd
			switch ($nd) {
				
				# Classify Multiple Value *nd Field; e.g. /records/172094/ (test #205)
				case 'Sr SGM':
					$string .= "Sr {$n2} {$n1} (SGM)"; break;
				case 'Lord, 1920-1999':
					$string .= "Lord {$n2} {$n1}"; break;	// /records/172094/ (test #205)
				case 'Rev., O.M.I.':
					$string .= "Rev. {$n2} {$n1}"; break;
				case 'I, Prince of Monaco':
					$string .= "{$n1} I, Prince of Monaco"; break;
				case 'Baron, 1880-1957':
					$string .= "Baron {$n2} {$n1}"; break;
					
				# Classify Single Value *nd Field
				default:
					$string .= $this->classifySingleValueNdField ($n1, $n2, $nd);
			}
			
		# Add to 245 field: <*n2> <*n1> [or just <*n1> if no <*n2>]; e.g. /records/1134/ (test #206), /records/1113/ (test #207)
		} else {
			$string .= ($n2 ? $n2 . ' ' : '');
			$string .= $n1;
		}
		
		# Return the string
		return $string;
	}
	
	
	# Function to unexpand initials to remove spaces; this is the opposite of spaceOutInitials() in generateAuthors
	private function unspaceOutInitials ($string)
	{
		# Any initials should be not be separated by a space; e.g. /records/203294/ , /records/203317/ , /records/6557/ , /records/202992/, /records/1135/ (test #202)
		# This is tolerant of transliterated Cyrillic values, e.g. /records/194996/ which has "Ye.V." - actually no longer relevant; however, /records/194996/ confirms transliterated version works fine (test #208)
		# This also ensures each group is an initial, e.g. avoiding /records/1139/ which has "C. Huntly" (test #209); /records/1410/ which has S. le R. (test #203)
		$regexp = '/\b([^ ]{1,2})(\.) ([^ ]{1,2})(\.)/u';
		while (preg_match ($regexp, $string)) {
			$string = preg_replace ($regexp, '\1\2\3\4', $string);
		}
		
		# Return the amended string
		return $string;
	}
	
	
	# Classify Single Value *nd Field
	private function classifySingleValueNdField ($n1, $n2, $nd)
	{
		# Does the value of the *nd appear on the Prefix list?
		$prefixes = $this->entitiesToUtf8List ($this->prefixes ());	// e.g. /records/19668/ has entities (test #212)
		if (in_array ($nd, $prefixes)) {
			
			# Add to 245 field: <*nd> <*n2> <*n1>; e.g. /records/184117/ (records #210) [or just <*nd> <*n1> if no <*n2>; e.g. /records/4252/ (records #211) ]
			$string  = $nd . ' ';
			$string .= ($n2 ? $n2 . ' ' : '');
			$string .= $n1;
			return $string;
		}
		
		# Does the value of the *nd appear on the Between *n1 and *n2 list? E.g. /records/3180/ (test #213)
		$betweenN1AndN2 = $this->entitiesToUtf8List ($this->betweenN1AndN2 ());
		if (in_array ($nd, $betweenN1AndN2)) {
			
			# Add to 245 field: <*n2>, <*nd> <*n1>
			$string  = $n2 . ', ';
			$string .= $nd . ' ';
			$string .= $n1;
			return $string;
		}
		
		# Add to 245 field: <*n2> <*n1>, <*nd>; e.g. /records/1983/ (records #214) [or just <*n1>, <*nd> if no <*n2>; e.g. /records/4019/ (records #215#) ]
		$string  = ($n2 ? $n2 . ' ' : '');
		$string .= $n1 . ', ';
		$string .= $nd;
		return $string;
	}
	
	
	# Function to convert entities in a list (e.g. &eacute => �) to unicode; e.g. /records/19668/ has entities (test #212)
	#!#C Copied from generateAuthors; confirmed the same in January 2017
	private function entitiesToUtf8List ($listRaw)
	{
		# Loop through each item in the list
		$list = array ();
		foreach ($listRaw as $key => $value) {
			$key   = html_entity_decode ($key);
			$value = html_entity_decode ($value);
			$list[$key] = $value;
		}
		
		# Return the amended list
		return $list;
	}
	
	
	# List of prefixes; keep in sync with generateAuthors::prefixes()
	#!#C Copied from generateAuthors; confirmed the same in January 2017
	private function prefixes ()
	{
		return array (
			'Commander',
			'Hon',
			'Sir',							// /records/1201/ (test #142)
			'Abb&eacute;',
			'Admiral',
			'Admiral Lord',
			'Admiral of the Fleet, Sir',
			'Admiral Sir',
			'Admiral, Sir',
			'Amiral',
			'Archdeacon',
			'Archpriest',
			'Baron',
			'Baroness',
			'Bishop',
			'Brigadier',
			'Brigadier-General',
			'Capit&aacute;n',				// /records/53959/ (test #143)
			'Capitan',
			'Capt.',
			'Captain',
			'Cdr',
			'Cdr.',
			'Chevalier',
			'Chief Justice',
			'Chief-Justice',
			'Cmdr',
			'Col.',
			'Colonel',
			'Commandant',
			'Commandante',
			'Commander',
			'Commodore',
			'Conte',
			'Contre-Amiral',
			'Coronel',
			'Count',
			'Cst.',
			'Doctor',
			'Dom',
			'Dr',
			'Dr.',
			'Duc',
			'Duke',
			'Earl',
			'Ensign',
			'Father',
			'Fr',
			'General',
			'General Sir',
			'General, Count',
			'General, Sir',
			'Graf',
			'Hon.',
			'Ing.',
			'Kapit&auml;n',
			'Kommand&oslash;rkaptajn',	// /records/19668/ (test #212)
			'Korv. Kapt.',
			'L\'Abb&eacute;',
			'l\'Ain&eacute;',
			'l\'amiral',
			'Lady',
			'Le Comte',
			'Lieut',
			'Lieut.',
			'Lieutenant Colonel',
			'Lieutenant General',
			'Lord',
			'Lt',
			'Lt Cdr',
			'Lt.',
			'Lt. Col.',
			'Maj. Gen.',
			'Major',
			'Major General',
			'Marquess',
			'Metropolitan',
			'Mme',
			'Mme.',
			'Mrs',
			'Mrs.',
			'Prince',
			'Professor',
			'Protoierey',
			'Rear Admiral',
			'Rear-Admiral',
			'Rev',
			'Rev.',
			'Rev. Dr.',
			'Rev\'d',
			'Revd',
			'Reverend',
			'Right Hon. Lord',
			'Ritter',
			'Rt. Hon.',
			'Sergeant',
			'Sir',
			'Sister',
			'Sr',
			'Sr.',
			'The Venerable',
			'Vice Admiral Sir',
			'Vice-Admiral',
			'Viscount',
			'Vlkh.',
		);
	}
	
	
	# List of between *n1 and *n2
	#!#C Copied from generateAuthors; confirmed the same in January 2017
	private function betweenN1AndN2 ()
	{
		return array (
			'Freiherr von',		// /records/3180/ (test #145)
		);
	}
}

?>