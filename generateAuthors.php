<?php

# Class to generate the complex author fields
class generateAuthors
{
	# Class properties
	private $values = array ();
	
	
	# Constructor
	public function __construct ($muscatConversion, $mainRecordXml)
	{
		# Create a class property handle to the parent class
		$this->muscatConversion = $muscatConversion;
		$this->databaseConnection = $muscatConversion->databaseConnection;
		$this->settings = $muscatConversion->settings;
		
		# Create a handle to the XML
		$this->mainRecordXml = $mainRecordXml;
		
		# Define unicode symbols
		$this->doubleDagger = chr(0xe2).chr(0x80).chr(0xa1);
		
		# Launch the two main entry points; each may include a mutation to a different field number
		$this->generate100 ();
		$this->generate700 ();
		
	}
	
	
	# Getter to return the values
	public function getValues ()
	{
		# Return the values
		return $this->values;
	}
	
	
	# 100 field generation entry point
	/*
	 * This is basically the first author.
	 * It may end up switching to 110/111 instead.
	 * Everyone else involved in the production ends put in 7xx fields.
	 *
	 */
	private function generate100 ()
	{
		# By default, assume no mutation
		$this->values[110] = false;
		$this->values[111] = false;
		
		# 100 is not relevant for *ser or *art/*in/*ag, so end at this point if matches these
		$ser = $this->muscatConversion->xPathValue ($this->mainRecordXml, '//ser');
		$artIn = $this->muscatConversion->xPathValue ($this->mainRecordXml, '//art/in');
		if ($ser || $artIn) {
			$this->values[100] = false;
			return;
		}
		
		# Do the classification; look at the first or only *doc/*ag/*a OR *art/*ag/*a
		$value = $this->main ($this->mainRecordXml, '/*/ag/a[1]');
		
		# Write the value into the values registry
		$this->values[100] = $value;
	}
	
	
	# 700 field generation entry point; see: http://www.loc.gov/marc/bibliographic/bd700.html
	/*
	 * This is basically all the people involved in the book except the first author, which if present is covered in 100/110/111.
	 * It includes people in the analytic (child) records, but limited to the first of them for each such child record
	 * This creates multiple 700 lines, the lines being created as the outcome of the loop below
	 * Each "contributor block" referenced below refers to the author components, which are basically the 'classify' functions elsewhere in this class
	 * 
	 * - Check there is *doc/*ag or *art/*ag (i.e. *ser records will be ignored)
	 * - Loop through each *ag
	 * - Within each *ag, for each *a and *al add the contributor block
	 * - In the case of each *ag/*al, the "*al Detail" block (and ", �g (alternative name)", once only) is added
	 * - Loop through each *e
	 * - Within each *e, for each *n add the contributor block
	 * - In the case of each *e/*n, *role, with Relator Term lookup substitution, is incorporated
	 * - When considering the *e/*n, there is a guard clause to skip cases of 'the author' as the 100 field would have already pulled in that person (e.g. the 100 field could create "<name> $eIllustrator" indicating the author <name> is also the illustrator)
	 * - Check for a *ke which is a flag indicating that there are analytic (child) records, e.g. as present in /records/7463/
	 * - Look up the records whose *kg matches, e.g. /records/9375/ has *kg=7463, so this indicates that 9375 (which will be an *art) is a child of 7463
	 * - For each *kg's *art (i.e. child *art record): take the first *art/*ag/*a/ (only the first) in that record within the *ag block, i.e. /records/9375/ /art/ag/a "contributor block", and also add the title (i.e. *art/*tg/*t); the second indicator is set to '2' to indicate that this 700 line is an 'Analytical entry'
	 * - Every 700 has a fixed string ", �5 UkCU-P." at the end (representing the Institution to which field applies)
	 */
	private function generate700 ()
	{
		# By default, assume no mutation
		$this->values[710] = false;
		$this->values[711] = false;
		
		# Start a list of 700 line values
		$lines = array ();
		
#!# Should an /art record with no ag but with e succeed?
		# Check there is *doc/*ag or *art/*ag (i.e. *ser records will be ignored)
		$docAg = $this->muscatConversion->xPathValue ($this->mainRecordXml, '/doc/ag');
		$artAg = $this->muscatConversion->xPathValue ($this->mainRecordXml, '/art/ag');
		if (!$docAg && !$artAg) {
			$this->values[700] = false;
			return;
		}
		
		# Loop through each *ag
		$agIndex = 1;
		while ($this->muscatConversion->xPathValue ($this->mainRecordXml, "/*/ag[$agIndex]")) {
			
			# Loop through each *a (author) in this *ag (author group)
			$aIndex = 1;	// XPaths are indexed from 1, not 0
			while ($this->muscatConversion->xPathValue ($this->mainRecordXml, "/*/ag[$agIndex]/a[{$aIndex}]")) {
				
				# Skip the first /*ag/*a
				if ($agIndex == 1 && $aIndex == 1) {
					$aIndex++;
					continue;
				}
				
				# Obtain the value
				$lines[] = $this->main ($this->mainRecordXml, "/*/ag[$agIndex]/a[{$aIndex}]", false);
				
				# Next *a
				$aIndex++;
			}
			
			# Loop through each *al (author) in this *ag (author group)
			$alIndex = 1;	// XPaths are indexed from 1, not 0
			while ($this->muscatConversion->xPathValue ($this->mainRecordXml, "/*/ag[$agIndex]/al[{$alIndex}]")) {
				
				# Obtain the value
				$line = $this->main ($this->mainRecordXml, "/*/ag[$agIndex]/al[{$alIndex}]", false);
				
				# The "*al Detail" block (and ", �g (alternative name)", once only) is added
				#!# Not yet checked cases for when a $g might already exist, to check this works
				if (!substr_count ($line, "{$this->doubleDagger}g")) {
					$line .= ", {$this->doubleDagger}g" . '(alternative name)';
				}
				
				# Register the line
				$lines[] = $line;
				
				# Next *al
				$alIndex++;
			}
			
			# Next *ag
			$agIndex++;
		}
		
		# Loop through each *e
		$eIndex = 1;
		while ($this->muscatConversion->xPathValue ($this->mainRecordXml, "/*/e[$eIndex]")) {
			
			# Within each *e, for each *n add the contributor block
			$nIndex = 1;	// XPaths are indexed from 1, not 0
			while ($this->muscatConversion->xPathValue ($this->mainRecordXml, "/*/e[$eIndex]/n[{$nIndex}]")) {
				
				# When considering the *e/*n, there is a guard clause to skip cases of 'the author' as the 100 field would have already pulled in that person (e.g. the 100 field could create "<name> $eIllustrator" indicating the author <name> is also the illustrator); e.g. /records/147053/
				#!# Move this check into the main processing?
				#!# Check this is as expected for e.g. /records/147053/
				$n1 = $this->muscatConversion->xPathValue ($this->mainRecordXml, "/*/e[$eIndex]/n[{$nIndex}]/n1");
				if ($n1 == 'the author') {
					$nIndex++;
					continue;
				}
				
				# Obtain the value
				# In the case of each *e/*n, *role, with Relator Term lookup substitution, is incorporated; example: /records/47079/ ; this is done inside classifyAdField ()
				$line = $this->main ($this->mainRecordXml, "/*/e[$eIndex]/n[{$nIndex}]", false);
				
				# Register the line
				$lines[] = $line;
				
				# Next *n
				$nIndex++;
			}
			
			# Next *e
			$eIndex++;
		}
		
		# Check for a *ke which is a flag indicating that there are analytic (child) records; e.g.: /records/7463/
		if ($this->muscatConversion->xPathValue ($this->mainRecordXml, '//ke')) {		// Is just a flag, not a useful value; e.g. record 7463 contains "\&lt;b&gt; Analytics \&lt;b(l) ~l 1000/&quot;ME7463&quot;/ ~&gt;" which creates a button in the Muscat GUI
			
			# Look up the records whose *kg matches, e.g. /records/9375/ has *kg=7463, so this indicates that 9375 (which will be an *art) is a child of 7463
			$currentRecordId = $this->muscatConversion->xPathValue ($this->mainRecordXml, '/q0');
			if ($children = $this->getAnalyticChildren ($currentRecordId)) {	// Returns records as array (id=>xmlObject, ...)
				
				# Loop through each *kg's *art (i.e. child *art record)
				foreach ($children as $id => $childRecordXml) {
					
					# Take the first *art/*ag/*a/ (only the first) in that record within the *ag block, i.e. /records/9375/ /art/ag/a "contributor block"; the second indicator is set to '2' to indicate that this 700 line is an 'Analytical entry'
					$line = $this->main ($childRecordXml, "/*/ag[1]/a[1]", false, '2');
					
					# Add the title (i.e. *art/*tg/*t)
					$line .= ", {$this->doubleDagger}t" . $this->muscatConversion->xPathValue ($childRecordXml, '/*/tg/t');
					
					# Register the line
					$lines[] = $line;
				}
			}
		}
		
		# End if no lines
		if (!$lines) {
			$this->values[700] = false;
			return;
		}
		
		# Every 700 has a fixed string ", �5 UkCU-P." at the end (representing the Institution to which field applies)
		foreach ($lines as $index => $line) {
			$lines[$index] .= ", {$this->doubleDagger}5" . 'UkCU-P.';
		}
		
		# Implode the lines
		$newLine = "\n" . '700 ';
		$value = implode ($newLine, $lines);
		
		# Write the value, which will be a special multiline string, into the values registry
		$this->values[700] = $value;
	}
	
	
	# Function to obtain the analytic children
	private function getAnalyticChildren ($parentId)
	{
		# Get the children
		$childIds = $this->databaseConnection->selectPairs ($this->settings['database'], 'catalogue_processed', array ('field' => 'kg', 'value' => $parentId), array ('recordId'));
		
		# Load the XML records for the children
		$children = $this->databaseConnection->selectPairs ($this->settings['database'], 'catalogue_xml', array ('id' => $childIds), array ('id', 'xml'));
		
		# Convert each XML record string to an XML object
		$childrenRecords = array ();
		foreach ($children as $id => $record) {
			$childrenRecords[$id] = $this->muscatConversion->loadXmlRecord ($record);
		}
		
		# Return the records
		return $childrenRecords;
	}
	
	
	# Function providing an entry point into the main classification, which switches between the name format
	private function main ($xml, $path, $context1xx = true, $secondIndicator = '#')
	{
		# Start the value
		$value = '';
		
		# Create a handle to the XML for this field
		$this->xml = $xml;
		
		# Create a handle to the second indicator
		$this->secondIndicator = $secondIndicator;
		
		# Create a handle to the context1xx flag
		$this->context1xx = $context1xx;
		
		# Does the *a contain a *n2?
		$n2 = $this->muscatConversion->xPathValue ($this->xml, $path . '/n2');
		$n1 = $this->muscatConversion->xPathValue ($this->xml, $path . '/n1');
		if (strlen ($n2)) {
			
			# Add to 100 field: 1, second indicator, �a <*a/*n1>,
			$value .= "1{$this->secondIndicator} {$this->doubleDagger}a{$n1}, ";
			
			# Classify *n2 field
			$value = $this->classifyN2Field ($path, $value, $n2);
			
		} else {
			
			# Classify *n1 field
			$value = $this->classifyN1Field ($path, $value, $n1);
		}
		
		# Return the value
		return $value;
	}
	
	
	# Function to classify *n1 field
	private function classifyN1Field ($path, $value, $n1)
	{
		# Start the value for this section
		$value = '';
		
		# Is the *n1 exactly equal to a set of specific strings?
		$strings = array (
			'other members of the expedition',
			'others',
		);
		if (application::iin_array ($n1, $strings)) {
			
			# If yes, no 100 field (or any 1XX field) required
			return false;	// Resets $value
		}
		
		# Is the *n1 exactly equal to a set of specific strings?
		$strings = array (
			'-',
			'Anon',
			'Anon.',
			'[Anon.]',
			'[n.p.]',
			'Unknown',
		);
		if (application::iin_array ($n1, $strings)) {
			
			# Add to 100 field
			$value .= "0{$this->secondIndicator} {$this->doubleDagger}aAnonymous";
			
			# GO TO: Classify *ad Field
			$value = $this->classifyAdField ($path, $value);
			
			# End
			return $value;
		}
		
		# Is the *n1 exactly equal to any of the names in the 'Name in direct order' tab?
		$strings = $this->entitiesToUtf8List ($this->namesInDirectOrder ());
		if (in_array ($n1, $strings)) {
			
			# Add to 100 field
			$value .= "0{$this->secondIndicator} {$this->doubleDagger}a{$n1}";
			
			# Classify *nd Field
			$value = $this->classifyNdField ($path, $value);
			
			# End
			return $value;
		}
		
		# Is the *n1 exactly equal to any of the names in the 'Surname only' tab?
		$surnameOnly = $this->entitiesToUtf8List ($this->surnameOnly ());
		if (in_array ($n1, $surnameOnly)) {
			
			# Add to 100 field
			$value .= "1{$this->secondIndicator} {$this->doubleDagger}a{$n1}";
			
			# Classify *nd Field
			$value = $this->classifyNdField ($path, $value);
			
			# End
			return $value;
		}
		
		# Does the *n1 contain any of the following specific strings?
		$strings = array (
			'colloque',
			'colloquy',
			'conference',
			'congr&eacute;s',
			'congreso',
			'congress', // but NOT 'United States'
			'konferentsiya',
			'konferenzen',
			'inqua',
			'polartech',
			'symposium',
			'tagung',
		);
		$strings = $this->entitiesToUtf8List ($strings);
		$match = false;
		foreach ($strings as $string) {
			if (substr_count (strtolower ($n1), strtolower ($string))) {
				if (($string == 'congress') && (substr_count (strtolower ($n1), strtolower ('United States')))) {continue;}		// Whitelist this one
				$match = true;
				break;
			}
		}
		if ($match) {
			
			# Create 111/711 field instead of 100/700 field
			if ($this->context1xx) {
				$this->generate111 ();
			} else {
				$this->generate711 ();
			}
			
			# No value for 100/700 field
			return false;
		}
		
		# Create 110/710 field instead of 100/700 field
		if ($this->context1xx) {
			$this->generate110 ();
		} else {
			$this->generate710 ();
		}
		
		# No value for 100/700 field
		return false;
	}
	
	
	# Generate 110
	private function generate110 ()
	{
		
		# Write the value into the values registry
		$this->values[110] = 'todo-generate-110';
	}
	
	
	# Generate 111
	private function generate111 ()
	{
		
		# Write the value into the values registry
		$this->values[111] = 'todo-generate-111';
	}
	
	
	# Generate 710
	private function generate710 ()
	{
		
		# Write the value into the values registry
		$this->values[710] = 'todo-generate-710';
	}
	
	
	# Generate 711
	private function generate711 ()
	{
		
		# Write the value into the values registry
		$this->values[711] = 'todo-generate-710';
	}
	
	
	# Function to classify *n2 field
	private function classifyN2Field ($path, $value, $n2)
	{
		# Is the *n2 exactly equal to a set of specific names?
		$names = array (
			'David B. (David Bruce)',
			'H. (Herv&eacute;)',
			'H. (Hippolyte)',
			'K.V. (Konstantin Viktorovich)',
			'L. (Letterio)',
			'M. M. (Marilyn M.)',
			'O. (Osmund)',
			'R.D. (Reginald D.)',
			'R.D. (Robert D.)',
			'V. C. (Vanessa C.)',
		);
		$names = $this->entitiesToUtf8List ($names);
		if (in_array ($n2, $names)) {
			
			# Add to 100 field: <*a/*n2 [portion before brackets]> �q<*a/*n2 [portion in brackets, including brackets]>
			preg_match ('/^(.+) (\(.+\))$/', $n2, $matches);
			$value .= $matches[1];
			$value .= " {$this->doubleDagger}q" . $matches[2];
			
		} else {
			
			# Add to 100 field: <*a/*n2>
			$value .= $n2;
		}
		
		# Classify *nd Field
		$value = $this->classifyNdField ($path, $value);
		
		# Return the value
		return $value;
	}
	
	
	# Function to classify *nd field
	private function classifyNdField ($path, $value)
	{
		# Does the *a contain a *nd?
		$nd = $this->muscatConversion->xPathValue ($this->xml, $path . '/nd');
		if (!strlen ($nd)) {
			
			# If no, GO TO: Classify *ad Field
			$value = $this->classifyAdField ($path, $value);
			
			# Return the value
			return $value;
		}
		
		# If present, strip out leading '\v' and trailing '\n'
		$nd = preg_replace ('|^\\v(.+)\\n$|', '\1', $nd);
		
		# Is the *nd exactly equal to set of specific strings?
		$strings = array (
			'Sr SGM'				=> ", {$this->doubleDagger}c Sr, {$this->doubleDagger}u SGM",
			'Lord, 1920-1999'		=> ", {$this->doubleDagger}c Lord, {$this->doubleDagger}d 1920-1999",
			'Rev., O.M.I.'			=> ", {$this->doubleDagger}c Rev., {$this->doubleDagger}u O.M.I.",
			'I, Prince of Monaco'	=> ", {$this->doubleDagger}b I, {$this->doubleDagger}c Prince of Monaco",
			'Baron, 1880-1957'		=> ", {$this->doubleDagger}c Baron, {$this->doubleDagger}d 1880-1957",
		);
		if (array_key_exists ($nd, $strings)) {
			
			# Classify multiple value *nd field
			$value = $this->classifyMultipleValueNdField ($value, $nd, $strings);
			
		} else {
			
			# Classify single value *nd field
			$value = $this->classifySingleValueNdField ($value, $nd);
		}
		
		# GO TO: Classify *ad Field
		$value = $this->classifyAdField ($path, $value);
		
		# Return the value
		return $value;
	}
	
	
	# Function to classify multiple value *nd field
	private function classifyMultipleValueNdField ($value, $nd, $strings)
	{
		# Add the looked-up value
		$value .= $strings[$nd];
		
		# Return the value
		return $value;
	}
	
	
	# Function to classify single value *nd field
	private function classifySingleValueNdField ($value, $nd)
	{
		# Delegate
		return $value = $this->_classifySingleValueNdOrAdField ($value, $nd, false);
	}
	
	
	# Helper function to classify a single value *nd or *ad field
	private function _classifySingleValueNdOrAdField ($value, $fieldValue, $checkDateList)
	{
		# Does the value of the $fieldValue appear on the Prefix list?
		# Does the value of the $fieldValue appear on the Suffix list?
		# Does the value of the $fieldValue appear on the Between *n1 and *n2 list?
		$prefixes = $this->entitiesToUtf8List ($this->prefixes ());
		$suffixes = $this->entitiesToUtf8List ($this->suffixes ());
		$betweenN1AndN2 = $this->entitiesToUtf8List ($this->betweenN1AndN2 ());
		if (in_array ($fieldValue, $prefixes) || in_array ($fieldValue, $suffixes) || in_array ($fieldValue, $betweenN1AndN2)) {
			$value .= ", {$this->doubleDagger}c {$fieldValue}";
			return $value;
		}
		
		# Check the date list if required
		if ($checkDateList) {
			
			# Does the value of the $fieldValue appear on the Date list?
			$dateList = $this->dateList ();
			if (in_array ($fieldValue, $dateList)) {
				$value .= ", {$this->doubleDagger}d {$fieldValue}";
				return $value;
			}
		}
		
		# Do one or more words or phrases in the $fieldValue appear in the Relator terms list?
		$relatorTerms = $this->getRelatorTerms ($fieldValue);
		if (array_key_exists ($fieldValue, $relatorTerms)) {
			$value .= ", {$this->doubleDagger}e {$relatorTerms[$fieldValue]}";
			return $value;
		}
		
		# Does the value of the $fieldValue appear on the Misc. list?
		$miscList = $this->miscList ();
		if (in_array ($fieldValue, $miscList)) {
			$value .= ", {$this->doubleDagger}g ({$fieldValue})";
			return $value;
		}
		
		# Does the value of the $fieldValue appear on the Affiliation list?
		$affiliationList = $this->affiliationList ();
		if (in_array ($fieldValue, $affiliationList)) {
			$value .= ", {$this->doubleDagger}u {$fieldValue}";
			return $value;
		}
		
		# No change
		return $value;
	}
	
	
	# Function to get the relator terms
	private function getRelatorTerms ($valueForPrefiltering)
	{
		# Get the raw list
		$relatorTermsRaw = $this->relatorTerms ();
		
		# Process into value => replacement; these have already been checked for uniqueness when replaced
		$relatorTerms = array ();
		foreach ($relatorTermsRaw as $parent => $children) {
			foreach ($children as $child) {
				
				# Deal with pre-filters, which contain // in the terms list
				if (preg_match ('|(.+)//(.+):(.+)|', $child, $matches)) {
					$valueForPrefiltering = strtolower ($valueForPrefiltering);
					$matches[3] = strtolower ($matches[3]);
					
					# Determine whether to keep the entry in place
					switch ($matches[2]) {
						
						# Only - the entire string must match the specified value; e.g. "with//ONLY:with" will be ignored if the $valueForPrefiltering was "with foo"
						case 'ONLY':
							$keep = ($matches[3] == $valueForPrefiltering);
							break;
							
						# Not - the string must not contain the specified value; e.g. "director//NOT:art director" will be ignored if the $valueForPrefiltering was "art director"
						case 'NOT':
							$keep = (!substr_count ($matches[3], $valueForPrefiltering));
							break;
							
						# Requires - the overall record must have the specified XPath entry
						case 'REQUIRES':
							$keep = ($this->muscatConversion->xPathValue ($this->xml, $matches[3]));
							break;
					}
					
					# If a match is supported, substitute the key; otherwise remove the entry - which will mean it never matches in the later comparison stages
					if ($keep) {
						// echo "DEBUG: Changing {$child} to {$matches[1]}<br />";
						$child = $matches[1];
					} else {
						// echo "DEBUG: Removing {$child}<br />";
						continue;	// Skip this term, i.e. do not register the value
					}
				}
				
				# Register the value
				$relatorTerms[$child] = $parent;
			}
		}
		
		// application::dumpData ($relatorTerms);
		
		# Convert entities
		$relatorTerms = $this->entitiesToUtf8List ($relatorTerms);
		
		# Return the list
		return $relatorTerms;
	}
	
	
	# Function to convert entities in a list (e.g. &eacute => �) to unicode
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
	
	
	# Function to classify *ad field
	private function classifyAdField ($path, $value)
	{
		# If running in a 7** context, and going through *e/*n, trigger the "Classify *e Field" subroutine check
		#!# Not clear this is necessary; in /records/147053/ this ends up getting $e anyway even if this block is not here
		if (!$this->context1xx) {
			if (preg_match ('|^/\*/e|', $path)) {
				$role = $this->muscatConversion->xPathValue ($this->xml, $path . '/preceding-sibling::role');
				$value .= $this->addRelatorTermsEField ($role);
				return $value;
			}
		}
		
		# Look at the first or only *doc/*ag OR *art/*ag; example: /records/1165/
		$ad = $this->muscatConversion->xPathValue ($this->xml, $path . '/following-sibling::ad');
		if (strlen ($ad)) {
			$value = $this->_classifySingleValueNdOrAdField ($value, $ad, true);
		}
		
		# GO TO: Add *aff Field
		$value = $this->addAffField ($path, $value);
		
		# Return the value
		return $value;
	}
	
	
	# Function to add *aff field
	private function addAffField ($path, $value)
	{
		# Is there a *aff in *doc/*ag OR *art/*ag?
		# If so, Add to 100 field; example: /records/121449/
		$aff = $this->muscatConversion->xPathValue ($this->xml, $path . '/following-sibling::aff');
		if (strlen ($aff)) {
			$value .= ", {$this->doubleDagger}u {$aff}";
		}
		
		# Does the record contain a *doc/*e/*n/*n1 OR *art/*e/*n/*n1 that is equal to 'the author'?
		# E.g. *e containing "Illustrated and translated by" and *n1 "the author"
		$n1 = $this->muscatConversion->xPathValue ($this->xml, '//e/n/n1');
		#!# Exactly equal? Currently this does not match the *e/*n check
		if (preg_match ('/the author/', $n1)) {
			$role = $this->muscatConversion->xPathValue ($this->xml, '//e/role');	// Obtain the $role, having determined that *n1 matches "the author"
			$value .= $this->addRelatorTermsEField ($role);
		}
		
		# Does 100 field currently end with a punctuation mark?
		if (!preg_match ('/[.)\]\-,;:]$/', $value)) {	// http://stackoverflow.com/a/5484178 says that only ^-]\ need to be escaped inside a character class
			$value .= '.';
		}
		
		# Does 100 field include either or both of the following specified relator terms
		if ($this->context1xx) {
			if (substr_count ($value, "{$this->doubleDagger}e editor") || substr_count ($value, "{$this->doubleDagger}e compiler")) {
				
				# Change 100 field to 700 field: all indicators, fields and subfields remain the same
				$this->values[700] = $value;
				return false;	// for 100
			}
		}
		
		# Return the value
		return $value;
	}
	
	
	# Function to add the relator term as a $e field
	private function addRelatorTermsEField ($role)
	{
		# Start a value
		$value = '';
		
		# Add to 100 field:
		# For each matching word / phrase, add:
		$relatorTerms = $this->getRelatorTerms ($role);
		$replacements = array ();
		foreach ($relatorTerms as $relatorTerm => $replacement) {
			if (substr_count ($role, $relatorTerm)) {
				$replacements[$relatorTerm] = $replacement;
			}
		}
		if ($replacements) {
			$replacements = array_unique ($replacements);
			foreach ($replacements as $replacement) {
				$value .= ", {$this->doubleDagger}e{$replacement}";
			}
		}
		
		# Return the value
		return $value;
	}
	
	
	# List of names in direct order
	private function namesInDirectOrder ()
	{
		return array (
			'A.Z.',
			'Adam av Bremen',
			'Adam of Bremen',
			'Adamus Bremensis',
			'Albert',
			'Alman',
			'Anne',
			'Author of "Harry Lawton\'s adventures"',
			'B.M.C.',
			'B.R.',
			'Bonxie',
			'C.D.O.',
			'C.E.L.',
			'C.L.',
			'Captain "Quaker" Oates',
			'Charles',
			'Copper River Joe',
			'Dalan',
			'DJ Spooky',
			'\'Drawer D\'',
			'Dufferin and Ava',
			'E.L.H.',
			'E.P.',
			'Earl of Carnarvon',
			'Earl of Ellesmere',
			'Earl of Southesk',
			'El Presidente de la Nacion Argentina',
			'F.W.I.',
			'[G.F.]',
			'G.F.',
			'George-Day',
			'Georgia',
			'Grant',
			'H.A.',
			'H.B.',
			'H.J.I.',
			'H.L.L.',
			'H.W.B.',
			'Haakon',
			'Heikk&aacute;-Gustu',
			'Herg&eacute;',
			'Ignatius',
			'Innocent of Kamchatka',
			'Inspector and doctor',
			'J.K.',
			'J.M.W.',
			'J.W.G.',
			'Jorsep',
			'K.B.',
			'L.Fr.',
			'Luda',
			'M.A.A.',
			'M.B.H.',
			'M.Kh.',
			'M.M.',
			'M.S.F.',
			'M.V.C.',
			'M.W.R.',
			'Malak',
			'Markoosie',
			'Matthew',
			'Mr. Penguin',
			'N.B.K.',
			'O.A.',
			'P.L.',
			'Philip',
			'Qian-Jin',
			'R.E.P.',
			'R.N.R.B.',
			'S.T.',
			'S.T.A.M.',
			'Sis-hu-lk',
			'Torwil',
			'Ungaralak',
			'W.E.C.',
			'W.E.R.',
			'W.J.',
			'W.S.B.',
			'Xenophon',
			'Yambo',
		);
	}
	
	
	# List of surname only
	private function surnameOnly ()
	{
		return array (
			'Aberdare',
			'Arkturus',
			'Baird',
			'Beebe',
			'Bellebon',
			'Boulangier',
			'Bourne',
			'Brightman',
			'Brorsen',
			'Bruchhausen',
			'Chisholm',
			'Christison',
			'de Bieberstein',
			'de Met',
			'Delisle',
			'di Georgia',
			'Dumas',
			'Eitel',
			'Ellyay',
			'Fabricius',
			'Fabritius',
			'Gordeyev',
			'Gronen',
			'Greene',
			'Haetta',
			'Hammelmann',
			'Harold',
			'Hauge',
			'Henrik',
			'Howard',
			'Hue',
			'Husker',
			'Ishkov',
			'Ivanov',
			'Jackson',
			'Jansen',
			'Joly',
			'Jones',
			'Kaunhowen',
			'Kerr',
			'Kretschmer',
			'Krokisius',
			'Krusenstern',
			'Le Gentil',
			'Le Monnier',
			'Lindeman',
			'Lloyd',
			'Mackinnon',
			'Mackintosh',
			'Mayne',
			'McDonald',
			'Michell',
			'Microft',
			'M&uuml;ller',
			'Naidu',
			'Noummeline',
			'O\'Connell',
			'Oliver',
			'Palmer',
			'Pearce',
			'Pickwick',
			'Puff',
			'Redgrave',
			'Repsold',
			'Rice',
			'Rivinus',
			'Rosinha',
			'Rybin',
			'Samter',
			'Sharp',
			'Shul\'ts',
			'Siden',
			'S&ouml;derbergh',
			'Strauch',
			'Sunman',
			'Tabarin',
			'Thorpe',
			'Tsiklinsky',
			'Vecheri',
			'Vismara',
			'Walther',
			'Warden',
			'Wareham',
			'Wiedemann',
			'Zach',
			'Zain-Azraai',
			'Zero',
			'Zeune',
		);
	}
	
	
	# List of prefixes
	private function prefixes ()
	{
		return array (
			'Commander',
			'Hon',
			'Sir',
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
			'Capit&aacute;n',
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
			'Kommand&oslash;rkaptajn',
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
	
	
	# List of suffixes
	private function suffixes ()
	{
		return array (
			'10th Baron Strabolgi',
			'1st Baron',
			'1st Baron Mountevans',
			'1st Baron Moyne',
			'1st Baron Tweedsmuir',
			'1st Marquis of Dufferin and Ava',
			'2nd Baron',
			'2nd Baron Tweedsmuir',
			'4th Baron',
			'Agent-General for Victoria',
			'Archbishop of Uppsala',
			'Baron Ashburton',
			'Baroness Tweedsmuir',
			'Bishop of Exeter',
			'Bishop of Keewatin',
			'Bishop of Kingston',
			'Bishop of Tasmania',
			'Capt. US Navy (Ret)',
			'Captaine de fr&eacute;gate',
			'Col USAF (Ret.) Lt',
			'Comptroller and Auditor General',
			'D.D.S.',
			'D&eacute;put&eacute;-Maire des Sables-d\'Olonne',
			'Duchess of Bedford',
			'Earl of Northbrook',
			'Earl of Southesk',
			'Governor of Falkland Islands',
			'H.E. Ambassador',
			'II',
			'II.',
			'III',
			'III.',
			'IV',
			'Jnr',
			'Jr',
			'Jr, MD',
			'Jr.',
			'Junior',
			'K.C.B. K.C.',
			'Kapt. zur See',
			'King of Norway',
			'Lord Kennet',
			'Lord of Roberval',
			'Lt. Colonel, USAF-Retired',
			'M.D.',
			'MA, Phd',
			'Major, D.S.O.',
			'Marquess of Zetland',
			'O.M.',
			'Prince, consort of Elizabeth II, Queen of Great Britain',
			'Prince, consort of Margrethe II, Queen of Denmark',
			'Prince di Cannino',
			'Prince of Monaco',
			'Prince San Donato',
			'Princess Royal, daughter of Elizabeth II, Queen of Great Britain',
			'Rear Admiral, USN (Ret.)',
			'Rear Admiral, USN (Ret)',
			'S.J.',
			'SJ',
			'Sr',
			'the Apostle, Saint',
			'Third Baron',
		);
	}
	
	
	# List of between *n1 and *n2
	private function betweenN1AndN2 ()
	{
		return array (
			'Freiherr von',
		);
	}
	
	
	# Relator terms
	private function relatorTerms ()
	{
		return array (
			
			'abridger' => array (
				'abridged',
				'reduced',
			),
			
			'adapter' => array (
				'adaptation',
				'adapt&eacute;',
				'adapted',
				'l\'adaptation',
			),
			
			'analyst' => array (
				'analysis',
			),
			
			'annotator' => array (
				'annotated',
				'annot&eacute;',
				'annot&eacute;e',
			),
			
			'arranger' => array (
				'arranged',
				'arrangements',
				'arranger',
			),
			
			'art director' => array (
				'art direction',
			),
			
			'artist' => array (
				'art',
				'artist',
				'artwork',
				'designs',
				'dessin',
				'drawings',
				'graphics',
				'images',
				'paintings',
				'sketches',
				'tegninger',
				'watercolors',
				'water colours',
			),
			
			'author' => array (
				'author//NOT:author\'s',
				'bokspill',
				'sc&eacute;nario',
				'text',
				'texte',
				'textes',
				'writer',
				'written',
			),
			
			'cartographer' => array (
				'cartography',
				'map',
				'maps',
			),
			
			'cinematographer' => array (
				'camera',
				'cameraman',
				'cinematography',
				'filmed',
			),
			
			'collector' => array (
				'collected',
				'collector',
			),
			
			'colorist' => array (
				'couleur',
			),
			
			'commentator for written text' => array (
				'commentaries',
				'commentary',
				'commentator',
				'commentators',
				'commented',
				'comments',
				'erl&auml;utert',
				'kommentar',
				'kommentiert',
			),
			
			'compiler' => array (
				'chosen',
				'collator',
				'comp.',
				'compilation',
				'compiled',
				'compiler',
				'compilers',
				'dargeboten',
				'selected',
				'zusammengestellt',
			),
			
			'composer' => array (
				'composer',
				'music',
				'oper',
			),
			
			'conductor' => array (
				'conducted',
			),
			
			'consultant' => array (
				'consultant',
				'consulting',
			),
			
			'contributor' => array (
				'account',
				'additional material',
				'additional work',
				'additions',
				'afterword',
				'anhang',
				'appendices',
				'appendix',
				'article',
				'art&iacute;culos',
				'asesoramiento',
				'assessment',
				'assistance',
				'association with',
				'avant-propos',
				'baksats',
				'beitrag',
				'beitr&auml;gen',
				'berechnet',
				'bericht',
				'bibliography',
				'biography',
				'captain\'s logs',
				'captions',
				'chapter',
				'chapters',
				'chronology',
				'colaborador',
				'collaboration',
				'collaborations',
				'completed',
				'conclusion',
				'concours scientifique',
				'consultation',
				'contributary (?)',
				'contributing',
				'contribution',
				'contributions',
				'contributors',
				'data',
				'discussed',
				'doaimmahan',
				'documented',
				'efterskrift',
				'epilogue',
				'essay',
				'essays',
				'estudio preliminar',
				'extracts',
				'footnotes',
				'foreword',
				'forewords',
				'forord',
				'forsats',
				'glossary',
				'index',
				'indices',
				'inleiding',
				'insects',
				'interview with',
				'introducci&oacute;n',
				'introduced',
				'introducere',
				'introduction',
				'introductions',
				'introductory',
				'mitarbeit',
				'mithilfe',
				'mitwirkung',
				'narration written',
				'narratives',
				'notas',
				'note',
				'notes',
				'observations',
				'overview',
				'parallel account',
				'participation',
				'postface',
				'preamble',
				'preface',
				'pr&eacute;face',
				'pr&eacute;fac&eacute;',
				'prefaced',
				'pr&eacute;faces',
				'prefa??',
				'prefatory',
				'prefazione',
				'pr&eacute;sent&eacute;',
				'prologo',
				'pr&ouml;logo',
				'readings written',
				'remarks',
				'salutation',
				'special thanks',
				'sunto',
				'superintended',
				'supplemental material',
				'supplementary material',
				'table',
				'tabulation',
				'technical advisor',
				'tema',
				'texten',
				'together with',
				'updated',
				'vignettes',
				'voorwoord',
				'vorgemerkungen',
				'vorwort',
				'with//ONLY:with',	// Full match just for 'with'
				'zusammenarbeit',
			),
			
			'creator' => array (
				'created',
			),
			
			'curator' => array (
				'curator',
				'curators',
			),
			
			'designer' => array (
				'design',
				'designed',
				'gestaltung',
				'layout',
			),
			
			'director' => array (
				'directed',
				'director//NOT:art director',
				'directors',
				'sous la direction',
				'under the direction',
			),
			
			'editor' => array (
				'a cura',
				'&aacute;tdolgozta',
				'bearbeitet',
				'co-editor',
				'co-editors',
				'corrected',
				'ed.',
				'edit&eacute;',
				'edited',
				'editing',
				'editor//NOT:film editor',
				'editorial',
				'editor-in-chief',
				'editor\'s',
				'editors',
				'editors\'',
				'eds.',
				'herausgegeben',
				'prepared',
				'red.',
				'redactie',
				'redakcyjne',
				'redaksjon',
				'redaktor',
				'redaktsyey',
				'redigert',
				'revised//NOT:revised translation',
				'revision',
			),
			
			'engraver' => array (
				'engravings',
			),
			
			'film editor' => array (
				'film editor',
			),
			
			'filmmaker' => array (
				'film made',
			),
			
			'illustrator' => array (
				'illustrasjoner',
				'illustrated',
				'illustration',
				'illustrationen',
				'illustrations',
				'illustrator',
				'illustr&eacute;',
				'illustrert',
				'plates',
				'textzeichnungen',
			),
			
			'lithographer' => array (
				'lithographs',
				'lithographed',
			),
			
			'narrator' => array (
				'narrated',
				'narration',
				'narrator',
				'presented//REQUIRES://form',
				'presenter',
				'read',
			),
			
			'organizer' => array (
				'organiser',
				'organisers',
				'organising',
			),
			
			'panelist' => array (
				'panelists',
			),
			
			'performer' => array (
				'klavier-auszug',
			),
			
			'photographer' => array (
				'fotograf&iacute;a',
				'fotografien',
				'foto\'s',
				'panoramas',
				'photo',
				'photograph',
				'photographed',
				'photographer',
				'photographers',
				'photographies',
				'photographs',
				'photography',
				'photos',
			),
			
			'printer' => array (
				'printed',
			),
			
			'producer' => array (
				'produced',
				'producer',
				'producers',
				'production',
			),
			
			'publisher' => array (
				'published',
				'publishers',
			),
			
			'recordist' => array (
				'recorded',
				'recorder',
			),
			
			'redaktor' => array (
				'redacted',
				'redigit',
			),
			
			'reporter' => array (
				'reporter',
				'reporters',
			),
			
			'researcher' => array (
				'research',
				'researched',
			),
			
			'restorationist' => array (
				'restored',
			),
			
			'reviewer' => array (
				'reviewer',
				'reviewers',
			),
			
			'scientific advisor' => array (
				'botanical advisor',
			),
			
			'secretary' => array (
				'secretary',
			),
			
			'singer' => array (
				'gesang',
				'singer',
				'soprano',
			),
			
			'sponsor' => array (
				'sponsor',
				'sponsors',
			),
			
			'transcriber' => array (
				'fortalt',
				'retranscribed',
				'telescript',
				'told',
				'transcribed',
				'transcriber',
				'transcription',
				'transcriptions',
			),
			
			'translator' => array (
				'aus dem',
				'de l\'anglais',
				'deutsch von',
				'english version',
				'norwegian edition ',
				'oversat',
				'oversatt',
				'&ouml;vers&auml;ttning',
				'prevedla',
				'przektad',
				'tradu&ccedil;&atilde;o',
				'traducere',
				'traducido',
				'traduction',
				'traduit',
				'traduzione',
				'trans.',
				'translated',
				'translation',
				'translations',
				'translator',
				'translators',
				'&uuml;bersetzt',
				'&uuml;bersetzung',
				'&uuml;bertragen',
				'vertaald',
			),
			
			'witness' => array (
				'observer',
				'observers',
			),
		);
	}
	
	
	# Misc. list
	private function miscList ()
	{
		return array (
			'pseud.',
			'Campsterianus',
			'Pseud',
			'pseudonym',
		);
	}
	
	
	# Affiliation list
	private function affiliationList ()
	{
		return array (
			'OMI',
			'O.M.I.',
			'SGM',
			'Zoological Museum at Berlin',
		);
	}
	
	
	# Date list
	private function dateList ()
	{
		return array (
			'1863-1945',
		);
	}
}

?>
