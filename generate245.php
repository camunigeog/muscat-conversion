<?php

# Class to generate the complex 245 (Title and statement of responsibility) field; see: http://www.loc.gov/marc/bibliographic/bd245.html
class generate245
{
	# Constructor
	public function __construct ($muscatConversion, $xml, $authorsFields)
	{
		# Create a class property handle to the parent class
		$this->muscatConversion = $muscatConversion;
		
		# Create a handle to the XML
		$this->xml = $xml;
		
		# Create a handle to the authors fields
		$this->authorsFields = $authorsFields;
		
		# Determine the *form value
		$this->form = $this->muscatConversion->xPathValue ($this->xml, '(//form)[1]', false);
		
		# Define unicode symbols
		$this->doubleDagger = chr(0xe2).chr(0x80).chr(0xa1);
		
	}
	
	
	# Main
	public function main (&$error = false)
	{
		# Determine first and second indicator
		$firstIndicator = $this->firstIndicator ();
		$secondIndicator = $this->secondIndicator ();
		
		# Determine the title
		$title = $this->title ();
		
		# Determine the Statement of Responsibility
		$statementOfResponsibility = $this->statementOfResponsibility ();
		
		# Compile the value
		$value  = $firstIndicator;
		$value .= $secondIndicator;
		$value .= ' ';
		$value .= $title;
		$value .= $statementOfResponsibility;
		
		# Return the value
		return $value;
	}
	
	
	# First indicator
	private function firstIndicator ()
	{
		# Does this MARC record contain a 1XX field?
		return ($this->recordHas1xxField ($this->authorsFields) ? '1' : '0');
	}
	
	
	# Function to determine if the MARC record contains a 1XX field
	private function recordHas1xxField ($authorsFields)
	{
		# Determine if any 1XX field has a value
		foreach ($authorsFields as $marcCode => $value) {
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
		# Determine the record type
		$this->recordType = $this->recordType ();
		
		# Determine the main record type to use as the XPath, i.e. /art, /doc, or /ser
		$this->mainRecordTypePrefix = $this->recordType;
		if ($isArtRecord = (in_array ($this->recordType, array ('/art/in', '/art/j')))) {
			$this->mainRecordTypePrefix = '/art';
		}
		
		# Look at *ser/*tg/*t OR *doc/*tg/*t OR *art/*tg/*t
		$this->t = $this->muscatConversion->xPathValue ($this->xml, "/{$this->mainRecordTypePrefix}/tg/t");
		
		# Does the *t start with a leading article?
		$leadingArticleCharacterCount = $this->muscatConversion->macro_nfCount ($this->t, $this->xml);
		
		# Return the stop words count
		return $leadingArticleCharacterCount;
	}
	
	
	# Helper function to determine the record type
	#!# Copied from generate008 class
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
			if ($this->muscatConversion->xPathValue ($this->xml, $recordType)) {
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
		
		# Does the record contain a *form? If multiple *form values, separate using semicolon in same square brackets
		$form = $this->muscatConversion->xPathValue ($this->xml, '(//form)[1]', false);	// The data is known to have max one form per record
		
		# By default, flag that a Statement of Responsibility is not required
		$this->createStatementOfResponsibility = false;
		
		# Does the *t include a colon ':'?
		if (substr_count ($this->t, ':')) {
			
			#!# Need to check spacing rules here and added trimming; e.g. see /records/12359/
			
			# Add all text before colon
			$titleComponents = explode (':', $this->t, 2);
			$title .= $this->doubleDagger . 'a' . trim ($titleComponents[0]);
			
			# If there is a *form, Add to 245 field
			if ($form) {
				$title .= $this->doubleDagger . 'h[' . strtolower ($form) . ']';
			}
			
			# Add all text after colon
			$title .= ' :' . $this->doubleDagger . 'b' . trim ($titleComponents[1]);
			
		} else {
			
			# Add title
			$title .= $this->doubleDagger . 'a' . $this->t;
			
			# If there is a *form, Add to 245 field
			if ($form) {
				$title .= $this->doubleDagger . 'h[ ' . $form . ']';
			} else {
				
				# Are you creating this 245 field for a *ser record? If so, add . and end
				if ($this->recordType == '/ser') {
					$title .= '.';
				} else {
					$this->createStatementOfResponsibility = true;
				}
			}
		}
		
		# Return the title
		return $title;
	}
	
	
	# Statement of Responsibility
	private function statementOfResponsibility ()
	{
		# End if not required
		if (!$this->createStatementOfResponsibility) {return;}
		
		# Start the Statement of Responsibility
		$statementOfResponsibility = " /{$this->doubleDagger}c";
		
		# Look at first or only *doc/*ag/*a OR *art/*ag/*a
		# THEN: Is there another *a in the parent  *doc/*ag OR *art/*ag which has not already been included in this 245 field? E.g. see /records/181939/
		# THEN: Is there another *ag in the parent  *doc OR *art, whose *a fields have not already been included in this 245 field?
		$agIndex = 1;
		while ($this->muscatConversion->xPathValue ($this->xml, "{$this->mainRecordTypePrefix}/ag[$agIndex]")) {		// Check if *ag container exists
			
			# Separate multiple author groups with a semicolon-space
			if ($agIndex > 1) {
				$statementOfResponsibility .= '; ';
			}
			
			# Loop through each *a (author) in this *ag (author group)
			$aIndex = 1;	// XPaths are indexed from 1, not 0
			while ($string = $this->classifyNdField ("{$this->mainRecordTypePrefix}/ag[$agIndex]/a[{$aIndex}]")) {
				
				# Separate multiple authors with a comma-space
				if ($aIndex > 1) {
					$statementOfResponsibility .= ', ';
				}
				
				# Register this value
				$statementOfResponsibility .= $string;
				
				# Next *a
				$aIndex++;
			}
			
			# Is there a *ad in the parent  *doc/*ag OR *art/*ag?
			# Does the *ad have the value '-'?
			if ($ad = $this->muscatConversion->xPathValue ($this->xml, "{$this->mainRecordTypePrefix}/ag[$agIndex]/ad")) {
				if ($ad != '-') {
					$statementOfResponsibility .= ', ' . $ad;
				}
			}
			
			# Next *ag
			$agIndex++;
		}
		
		# Does the record contain at least one *e?; e.g. /records/2930/
		$eIndex = 1;
		while ($this->muscatConversion->xPathValue ($this->xml, "//e[$eIndex]")) {		// Check if *e container exists
			
			# Add to 245 field: ; <*e/*role>
			$statementOfResponsibility .= '; ' . $this->roleAndSiblings ("//e[$eIndex]");
			
			# Next e
			$eIndex++;
		}
		
		# Ensure the value ends with a dot (if not already present)
		if (mb_substr ($statementOfResponsibility, -1) != '.') {$statementOfResponsibility .= '.';}
		
		# Return the Statement of Responsibility
		return $statementOfResponsibility;
	}
	
	
	# Function to deal with a role and siblings; NB this is also used directly by the generate250b macro
	public function roleAndSiblings ($path)
	{
		# Start the result
		$result = '';
		
		# Obtain the role value
		$role = $this->muscatConversion->xPathValue ($this->xml, $path . '/role') . ' ';
		$result .= $role;
		
		# Add the entry
		$result .= $this->classifyNdField ($path . '/n');
		
		# Return the value
		return $result;
	}
	
	
	# Classify *nd Field
	private function classifyNdField ($pathPrefix)
	{
		# Start the string
		$string = '';
		
		# Obtain the n1/n2/nd values
		$n1 = $this->muscatConversion->xPathValue ($this->xml, $pathPrefix . '/n1');
		$n2 = $this->muscatConversion->xPathValue ($this->xml, $pathPrefix . '/n2');
		$nd = $this->muscatConversion->xPathValue ($this->xml, $pathPrefix . '/nd');
		
		# Does the *a or *n contain a *nd?
		if ($nd) {
			
			# If present, strip out leading '\v' and trailing '\n'; e.g. see /records/118086/
			$nd = strip_tags ($nd);		// \v and \n have been converted to HTML italic tags in the catalogue_processed stage
			
			# *nd
			switch ($nd) {
				
				# Classify Multiple Value *nd Field
				case 'Sr SGM':
					$string .= "Sr {$n2} {$n1} (SGM)"; break;
				case 'Lord, 1920-1999':
					$string .= "Lord {$n2} {$n1}"; break;
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
			
		# Add to 245 field: <*n2> <*n1> [or just <*n1> if no <*n2>]
		} else {
			$string .= ($n2 ? $n2 . ' ' : '');
			$string .= $n1;
		}
		
		# Return the string
		return $string;
	}
	
	
	# Classify Single Value *nd Field
	private function classifySingleValueNdField ($n1, $n2, $nd)
	{
		# Does the value of the *nd appear on the Prefix list?
		$prefixes = $this->entitiesToUtf8List ($this->prefixes ());
		if (in_array ($nd, $prefixes)) {
			
			# Add to 245 field: <*nd> <*n2> <*n1> [or just <*nd> <*n1> if no <*n2>]
			$string  = $nd . ' ';
			$string .= ($n2 ? $n2 . ' ' : '');
			$string .= $n1;
			return $string;
		}
		
		# Does the value of the *nd appear on the Between *n1 and *n2 list?
		$betweenN1AndN2 = $this->entitiesToUtf8List ($this->betweenN1AndN2 ());
		if (in_array ($nd, $betweenN1AndN2)) {
			
			# Add to 245 field: <*n2>, <*nd> <*n1>
			$string  = $n2 . ', ';
			$string .= $nd . ' ';
			$string .= $n1;
			return $string;
		}
		
		# Add to 245 field: <*n2> <*n1>, <*nd> [or just <*n1>, <*nd> if no <*n2>]
		$string  = ($n2 ? $n2 . ' ' : '');
		$string .= $n1 . ', ';
		$string .= $nd;
		return $string;
	}
	
	
	# Function to convert entities in a list (e.g. &eacute => �) to unicode
	#!# Copied from generateAuthors
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
	
	
	# List of prefixes
	#!# Copied from generateAuthors
	#!# Need to check this is intended to be exactly the same
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
	
	
	# List of between *n1 and *n2
	#!# Copied from generateAuthors
	private function betweenN1AndN2 ()
	{
		return array (
			'Freiherr von',
		);
	}
}

?>