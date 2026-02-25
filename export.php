<?php
/**
 * Scholarly Gateway - Export Functions
 * 
 * Contributors: Aditya Naryan Sahoo, Shruti Rawal, Dr. Kannan P
 */
 * Handles export functionality for publications in CSV, JSON, and Plain Text formats
 * No limit on records - exports all matching records
 */

// Include shared helper functions
require_once 'helpers.php';

/**
 * Get all metadata fields to export from Solr
 * This includes all available fields in the Solr indexer
 */
function getExportFields() {
    return [
        // Basic identifiers
        'id' => 'ID',
        'search.resourceid' => 'Resource ID',
        'handle' => 'Handle',
        'dc.bid' => 'Bid',
        
        // Title
        'dc.title' => 'Title',
        
        // Authors
        'dc.contributor.author' => 'Authors',
        'dc.contributor.advisor' => 'Advisor',
        'dc.contributor.affiliation' => 'Author Affiliation',
        'dc.contributor.department' => 'Author Department',
        'dc.contributor.editor' => 'Editor',
        'dc.contributor.other' => 'Other Contributors',
        
        // Dates
        'dc.date.accessioned' => 'Date Added',
        'dc.date.available' => 'Date Available',
        'dc.date.issued' => 'Date Issued',
        'dcterms.dateAccepted' => 'Date Accepted',
        
        // Description
        'dc.description.abstract' => 'Abstract',
        'dc.description.provenance' => 'Provenance',
        
        // Format
        'dc.format.extent' => 'Format/Extent',
        
        // Identifiers
        'dc.identifier.doi' => 'DOI',
        'dc.identifier.issn' => 'ISSN',
        'dc.identifier.isbn' => 'ISBN',
        'dc.identifier.uri' => 'URL',
        'dc.identifier.patentno' => 'Patent Number',
        'dc.identifier.pmid' => 'PMID',
        'dc.identifier.scopus' => 'Scopus ID',
        'dc.identifier.wos' => 'Web of Science ID',
        'dc.identifier.isi' => 'ISI ID',
        'dc.identifier.articleNumber' => 'Article Number',
        'dc.identifier.applicationnumber' => 'Application Number',
        'dc.identifier.sherpaUrl' => 'Sherpa URL',
        'dc.identifier.citation' => 'Citation',
        
        // Language
        'dc.language.iso' => 'Language',
        
        // Publisher
        'dc.publisher' => 'Publisher',
        'dc.source' => 'Source',
        'dc.relation.ispartof' => 'Journal/Series',
        'dc.relation.issn' => 'ISSN (Relation)',
        
        // Coverage
        'dc.coverage.spatial' => 'Country',
        
        // Type
        'dc.type' => 'Type',
        'dc.identifier.subtype' => 'Subtype',
        'dc.identifier.subtypeDescription' => 'Subtype Description',
        
        // Subjects
        'dc.subject' => 'Keywords/Subjects',
        'dc.subject.other' => 'Other Subjects',
        'dc.subject_scopus' => 'Scopus Subjects',
        'dc.subject_wos' => 'Web of Science Subjects',
        
        // Rights
        'dc.right' => 'Rights',
        'dc.rights' => 'Rights (Full)',
        
        // Citations
        'dc.identifier.citedby' => 'Cited By',
        'dc.identifier.crossref_citation' => 'Crossref Citation',
        
        // Open Access
        'oaire.freeToRead.value' => 'Free to Read',
        'oaire.venue.unpaywall' => 'Open Access',
        
        // Citation details
        'oaire.citation.volume' => 'Volume',
        'oaire.citation.issue' => 'Issue',
        'oaire.citation.startPage' => 'Start Page',
        'oaire.citation.endPage' => 'End Page',
        
        // Scopus/WOS metrics
        'dc.scopus.quartile' => 'Scopus Quartile',
        'dc.wos.quartile' => 'Web of Science Quartile',
        
        // CRIS fields
        'cris.author.scopus-author-id' => 'Scopus Author ID',
        'cris.lastimport.scopus' => 'Scopus Import Date',
        'cris.lastimport.scopus-publication' => 'Scopus Publication Import Date',
        'cris.sourceId' => 'Source ID',
        'cris.virtual.department' => 'CRIS Department',
        'cris.virtual.orcid' => 'ORCID',
        'cris.virtualsource.department' => 'CRIS Source Department',
        'cris.virtualsource.orcid' => 'CRIS Source ORCID',
        
        // Person identifiers
        'person.identifier.orcid' => 'ORCID (Person)',
        'person.identifier.rid' => 'Researcher ID',
        'person.identifier.scopus-author-id' => 'Person Scopus ID',
        
        // Person affiliation
        'person.affiliation.city' => 'Affiliation City',
        'person.affiliation.country' => 'Affiliation Country',
        'person.affiliation.id' => 'Affiliation ID',
        
        // OAI CERIF
        'oairecerif.author.affiliation' => 'CERIF Author Affiliation',
        'oairecerif.editor.affiliation' => 'CERIF Editor Affiliation',
        
        // Collection
        'location.coll' => 'Collection/Department'
    ];
}

/**
 * Build Solr query for export - retrieves all matching records without pagination limit
 * @param string $query Search query
 * @param array $filters Applied filters
 * @return array Solr query parameters
 */
function buildExportQuery($query, $filters, $rows = 100000) {
    global $SOLR_URL, $collection_to_dept;
    
    // Build filter query
    $filter_query = buildFilterQuery($filters);
    $count_fq = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    if (!empty($filter_query)) {
        $count_fq .= ' AND ' . $filter_query;
    }
    
    // Get all fields needed for export
    $export_fields = array_keys(getExportFields());
    $fl = implode(',', $export_fields);
    
    // Build Solr params - use provided rows or default to large number for all records
    if (!empty($query)) {
        $solr_params = [
            'q' => $query,
            'defType' => 'edismax',
            'qf' => 'dc.title^2 dc.contributor.author author_ac dc.source',
            'mm' => '1',
            'rows' => $rows,
            'start' => 0,
            'sort' => 'dateIssued.year desc',
            'wt' => 'json',
            'fl' => $fl
        ];
    } else {
        $solr_params = [
            'q' => '*:*',
            'rows' => $rows,
            'fl' => $fl
        ];
    }
    
    $solr_params['fq'] = $count_fq;
    
    return $solr_params;
}

/**
 * Process export results from Solr docs
 * @param array $docs Array of Solr documents
 * @return array Processed publications for export
 */
function processExportResults($docs) {
    global $collection_to_dept;
    
    $all_results = [];
    $fields = getExportFields();
    
    foreach ($docs as $doc) {
        $pub = [];
        
        // Basic identifiers
        $pub['ID'] = isset($doc['id']) ? (is_array($doc['id']) ? implode('; ', $doc['id']) : $doc['id']) : '';
        $pub['Resource ID'] = isset($doc['search.resourceid']) ? (is_array($doc['search.resourceid']) ? implode('; ', $doc['search.resourceid']) : $doc['search.resourceid']) : '';
        $pub['Handle'] = isset($doc['handle']) ? (is_array($doc['handle']) ? implode('; ', $doc['handle']) : $doc['handle']) : '';
        $pub['Bid'] = isset($doc['dc.bid']) ? (is_array($doc['dc.bid']) ? implode('; ', $doc['dc.bid']) : $doc['dc.bid']) : '';
        
        // Title
        $pub['Title'] = isset($doc['dc.title']) ? (is_array($doc['dc.title']) ? implode('; ', $doc['dc.title']) : $doc['dc.title']) : '';
        
        // Authors
        $authors = isset($doc['dc.contributor.author']) ? $doc['dc.contributor.author'] : [];
        $pub['Authors'] = is_array($authors) ? implode('; ', $authors) : ($authors ?? '');
        
        $advisor = isset($doc['dc.contributor.advisor']) ? $doc['dc.contributor.advisor'] : [];
        $pub['Advisor'] = is_array($advisor) ? implode('; ', $advisor) : ($advisor ?? '');
        
        $affiliation = isset($doc['dc.contributor.affiliation']) ? $doc['dc.contributor.affiliation'] : [];
        $pub['Author Affiliation'] = is_array($affiliation) ? implode('; ', $affiliation) : ($affiliation ?? '');
        
        $dept = isset($doc['dc.contributor.department']) ? $doc['dc.contributor.department'] : [];
        $pub['Author Department'] = is_array($dept) ? implode('; ', $dept) : ($dept ?? '');
        
        $editor = isset($doc['dc.contributor.editor']) ? $doc['dc.contributor.editor'] : [];
        $pub['Editor'] = is_array($editor) ? implode('; ', $editor) : ($editor ?? '');
        
        $other = isset($doc['dc.contributor.other']) ? $doc['dc.contributor.other'] : [];
        $pub['Other Contributors'] = is_array($other) ? implode('; ', $other) : ($other ?? '');
        
        // Dates
        $pub['Date Added'] = isset($doc['dc.date.accessioned']) ? (is_array($doc['dc.date.accessioned']) ? implode('; ', $doc['dc.date.accessioned']) : $doc['dc.date.accessioned']) : '';
        $pub['Date Available'] = isset($doc['dc.date.available']) ? (is_array($doc['dc.date.available']) ? implode('; ', $doc['dc.date.available']) : $doc['dc.date.available']) : '';
        $pub['Date Issued'] = isset($doc['dc.date.issued']) ? (is_array($doc['dc.date.issued']) ? implode('; ', $doc['dc.date.issued']) : $doc['dc.date.issued']) : '';
        $pub['Date Accepted'] = isset($doc['dcterms.dateAccepted']) ? (is_array($doc['dcterms.dateAccepted']) ? implode('; ', $doc['dcterms.dateAccepted']) : $doc['dcterms.dateAccepted']) : '';
        
        // Description
        $pub['Abstract'] = isset($doc['dc.description.abstract']) ? (is_array($doc['dc.description.abstract']) ? implode(' ', $doc['dc.description.abstract']) : $doc['dc.description.abstract']) : '';
        $pub['Provenance'] = isset($doc['dc.description.provenance']) ? (is_array($doc['dc.description.provenance']) ? implode('; ', $doc['dc.description.provenance']) : $doc['dc.description.provenance']) : '';
        
        // Format
        $pub['Format/Extent'] = isset($doc['dc.format.extent']) ? (is_array($doc['dc.format.extent']) ? implode('; ', $doc['dc.format.extent']) : $doc['dc.format.extent']) : '';
        
        // Identifiers
        $doi = isset($doc['dc.identifier.doi']) ? $doc['dc.identifier.doi'] : '';
        $pub['DOI'] = is_array($doi) ? implode('', $doi) : ($doi ?? '');
        
        $issn = isset($doc['dc.identifier.issn']) ? $doc['dc.identifier.issn'] : '';
        $pub['ISSN'] = is_array($issn) ? implode('', $issn) : ($issn ?? '');
        
        $isbn = isset($doc['dc.identifier.isbn']) ? $doc['dc.identifier.isbn'] : '';
        $pub['ISBN'] = is_array($isbn) ? implode('', $isbn) : ($isbn ?? '');
        
        $uri = isset($doc['dc.identifier.uri']) ? $doc['dc.identifier.uri'] : [];
        $pub['URL'] = is_array($uri) ? implode('; ', $uri) : ($uri ?? '');
        
        $pub['Patent Number'] = isset($doc['dc.identifier.patentno']) ? (is_array($doc['dc.identifier.patentno']) ? implode('; ', $doc['dc.identifier.patentno']) : $doc['dc.identifier.patentno']) : '';
        $pub['PMID'] = isset($doc['dc.identifier.pmid']) ? (is_array($doc['dc.identifier.pmid']) ? implode('; ', $doc['dc.identifier.pmid']) : $doc['dc.identifier.pmid']) : '';
        $pub['Scopus ID'] = isset($doc['dc.identifier.scopus']) ? (is_array($doc['dc.identifier.scopus']) ? implode('; ', $doc['dc.identifier.scopus']) : $doc['dc.identifier.scopus']) : '';
        $pub['Web of Science ID'] = isset($doc['dc.identifier.wos']) ? (is_array($doc['dc.identifier.wos']) ? implode('; ', $doc['dc.identifier.wos']) : $doc['dc.identifier.wos']) : '';
        $pub['ISI ID'] = isset($doc['dc.identifier.isi']) ? (is_array($doc['dc.identifier.isi']) ? implode('; ', $doc['dc.identifier.isi']) : $doc['dc.identifier.isi']) : '';
        $pub['Article Number'] = isset($doc['dc.identifier.articleNumber']) ? (is_array($doc['dc.identifier.articleNumber']) ? implode('; ', $doc['dc.identifier.articleNumber']) : $doc['dc.identifier.articleNumber']) : '';
        $pub['Application Number'] = isset($doc['dc.identifier.applicationnumber']) ? (is_array($doc['dc.identifier.applicationnumber']) ? implode('; ', $doc['dc.identifier.applicationnumber']) : $doc['dc.identifier.applicationnumber']) : '';
        $pub['Sherpa URL'] = isset($doc['dc.identifier.sherpaUrl']) ? (is_array($doc['dc.identifier.sherpaUrl']) ? implode('; ', $doc['dc.identifier.sherpaUrl']) : $doc['dc.identifier.sherpaUrl']) : '';
        $pub['Citation'] = isset($doc['dc.identifier.citation']) ? (is_array($doc['dc.identifier.citation']) ? implode('; ', $doc['dc.identifier.citation']) : $doc['dc.identifier.citation']) : '';
        
        // Language
        $lang = isset($doc['dc.language.iso']) ? $doc['dc.language.iso'] : [];
        $pub['Language'] = is_array($lang) ? implode('; ', $lang) : ($lang ?? '');
        
        // Publisher
        $pub['Publisher'] = isset($doc['dc.publisher']) ? (is_array($doc['dc.publisher']) ? implode('; ', $doc['dc.publisher']) : $doc['dc.publisher']) : '';
        $pub['Source'] = isset($doc['dc.source']) ? (is_array($doc['dc.source']) ? implode('; ', $doc['dc.source']) : $doc['dc.source']) : '';
        $pub['Journal/Series'] = isset($doc['dc.relation.ispartof']) ? (is_array($doc['dc.relation.ispartof']) ? implode('; ', $doc['dc.relation.ispartof']) : $doc['dc.relation.ispartof']) : '';
        $pub['ISSN (Relation)'] = isset($doc['dc.relation.issn']) ? (is_array($doc['dc.relation.issn']) ? implode('; ', $doc['dc.relation.issn']) : $doc['dc.relation.issn']) : '';
        
        // Coverage
        $country = isset($doc['dc.coverage.spatial']) ? $doc['dc.coverage.spatial'] : [];
        $pub['Country'] = is_array($country) ? implode('; ', $country) : ($country ?? '');
        
        // Type
        $pub['Type'] = isset($doc['dc.type']) ? (is_array($doc['dc.type']) ? implode('; ', $doc['dc.type']) : $doc['dc.type']) : '';
        $pub['Subtype'] = isset($doc['dc.identifier.subtype']) ? (is_array($doc['dc.identifier.subtype']) ? implode('; ', $doc['dc.identifier.subtype']) : $doc['dc.identifier.subtype']) : '';
        $pub['Subtype Description'] = isset($doc['dc.identifier.subtypeDescription']) ? (is_array($doc['dc.identifier.subtypeDescription']) ? implode('; ', $doc['dc.identifier.subtypeDescription']) : $doc['dc.identifier.subtypeDescription']) : '';
        
        // Subjects
        $subjects = isset($doc['dc.subject']) ? $doc['dc.subject'] : [];
        $pub['Keywords/Subjects'] = is_array($subjects) ? implode('; ', $subjects) : ($subjects ?? '');
        
        $subject_other = isset($doc['dc.subject.other']) ? $doc['dc.subject.other'] : [];
        $pub['Other Subjects'] = is_array($subject_other) ? implode('; ', $subject_other) : ($subject_other ?? '');
        
        $subject_scopus = isset($doc['dc.subject_scopus']) ? $doc['dc.subject_scopus'] : [];
        $pub['Scopus Subjects'] = is_array($subject_scopus) ? implode('; ', $subject_scopus) : ($subject_scopus ?? '');
        
        $subject_wos = isset($doc['dc.subject_wos']) ? $doc['dc.subject_wos'] : [];
        $pub['Web of Science Subjects'] = is_array($subject_wos) ? implode('; ', $subject_wos) : ($subject_wos ?? '');
        
        // Rights
        $pub['Rights'] = isset($doc['dc.right']) ? (is_array($doc['dc.right']) ? implode('; ', $doc['dc.right']) : $doc['dc.right']) : '';
        $pub['Rights (Full)'] = isset($doc['dc.rights']) ? (is_array($doc['dc.rights']) ? implode('; ', $doc['dc.rights']) : $doc['dc.rights']) : '';
        
        // Citations
        $pub['Cited By'] = isset($doc['dc.identifier.citedby']) ? (is_array($doc['dc.identifier.citedby']) ? implode('; ', $doc['dc.identifier.citedby']) : $doc['dc.identifier.citedby']) : '';
        $pub['Crossref Citation'] = isset($doc['dc.identifier.crossref_citation']) ? (is_array($doc['dc.identifier.crossref_citation']) ? implode('; ', $doc['dc.identifier.crossref_citation']) : $doc['dc.identifier.crossref_citation']) : '';
        
        // Open Access
        $pub['Free to Read'] = isset($doc['oaire.freeToRead.value']) ? (is_array($doc['oaire.freeToRead.value']) ? implode('; ', $doc['oaire.freeToRead.value']) : $doc['oaire.freeToRead.value']) : '';
        $pub['Open Access'] = isset($doc['oaire.venue.unpaywall']) ? (is_array($doc['oaire.venue.unpaywall']) ? implode('; ', $doc['oaire.venue.unpaywall']) : $doc['oaire.venue.unpaywall']) : '';
        
        // Citation details
        $volume = isset($doc['oaire.citation.volume']) ? $doc['oaire.citation.volume'] : '';
        $pub['Volume'] = is_array($volume) ? implode('', $volume) : ($volume ?? '');
        
        $issue = isset($doc['oaire.citation.issue']) ? $doc['oaire.citation.issue'] : '';
        $pub['Issue'] = is_array($issue) ? implode('', $issue) : ($issue ?? '');
        
        $startPage = isset($doc['oaire.citation.startPage']) ? $doc['oaire.citation.startPage'] : '';
        $pub['Start Page'] = is_array($startPage) ? implode('', $startPage) : ($startPage ?? '');
        
        $endPage = isset($doc['oaire.citation.endPage']) ? $doc['oaire.citation.endPage'] : '';
        $pub['End Page'] = is_array($endPage) ? implode('', $endPage) : ($endPage ?? '');
        
        // Scopus/WOS metrics
        $pub['Scopus Quartile'] = isset($doc['dc.scopus.quartile']) ? (is_array($doc['dc.scopus.quartile']) ? implode('; ', $doc['dc.scopus.quartile']) : $doc['dc.scopus.quartile']) : '';
        $pub['Web of Science Quartile'] = isset($doc['dc.wos.quartile']) ? (is_array($doc['dc.wos.quartile']) ? implode('; ', $doc['dc.wos.quartile']) : $doc['dc.wos.quartile']) : '';
        
        // CRIS fields
        $pub['Scopus Author ID'] = isset($doc['cris.author.scopus-author-id']) ? (is_array($doc['cris.author.scopus-author-id']) ? implode('; ', $doc['cris.author.scopus-author-id']) : $doc['cris.author.scopus-author-id']) : '';
        $pub['Scopus Import Date'] = isset($doc['cris.lastimport.scopus']) ? (is_array($doc['cris.lastimport.scopus']) ? implode('; ', $doc['cris.lastimport.scopus']) : $doc['cris.lastimport.scopus']) : '';
        $pub['Scopus Publication Import Date'] = isset($doc['cris.lastimport.scopus-publication']) ? (is_array($doc['cris.lastimport.scopus-publication']) ? implode('; ', $doc['cris.lastimport.scopus-publication']) : $doc['cris.lastimport.scopus-publication']) : '';
        $pub['Source ID'] = isset($doc['cris.sourceId']) ? (is_array($doc['cris.sourceId']) ? implode('; ', $doc['cris.sourceId']) : $doc['cris.sourceId']) : '';
        $pub['CRIS Department'] = isset($doc['cris.virtual.department']) ? (is_array($doc['cris.virtual.department']) ? implode('; ', $doc['cris.virtual.department']) : $doc['cris.virtual.department']) : '';
        $pub['ORCID'] = isset($doc['cris.virtual.orcid']) ? (is_array($doc['cris.virtual.orcid']) ? implode('; ', $doc['cris.virtual.orcid']) : $doc['cris.virtual.orcid']) : '';
        $pub['CRIS Source Department'] = isset($doc['cris.virtualsource.department']) ? (is_array($doc['cris.virtualsource.department']) ? implode('; ', $doc['cris.virtualsource.department']) : $doc['cris.virtualsource.department']) : '';
        $pub['CRIS Source ORCID'] = isset($doc['cris.virtualsource.orcid']) ? (is_array($doc['cris.virtualsource.orcid']) ? implode('; ', $doc['cris.virtualsource.orcid']) : $doc['cris.virtualsource.orcid']) : '';
        
        // Person identifiers
        $pub['ORCID (Person)'] = isset($doc['person.identifier.orcid']) ? (is_array($doc['person.identifier.orcid']) ? implode('; ', $doc['person.identifier.orcid']) : $doc['person.identifier.orcid']) : '';
        $pub['Researcher ID'] = isset($doc['person.identifier.rid']) ? (is_array($doc['person.identifier.rid']) ? implode('; ', $doc['person.identifier.rid']) : $doc['person.identifier.rid']) : '';
        $pub['Person Scopus ID'] = isset($doc['person.identifier.scopus-author-id']) ? (is_array($doc['person.identifier.scopus-author-id']) ? implode('; ', $doc['person.identifier.scopus-author-id']) : $doc['person.identifier.scopus-author-id']) : '';
        
        // Person affiliation
        $pub['Affiliation City'] = isset($doc['person.affiliation.city']) ? (is_array($doc['person.affiliation.city']) ? implode('; ', $doc['person.affiliation.city']) : $doc['person.affiliation.city']) : '';
        $pub['Affiliation Country'] = isset($doc['person.affiliation.country']) ? (is_array($doc['person.affiliation.country']) ? implode('; ', $doc['person.affiliation.country']) : $doc['person.affiliation.country']) : '';
        $pub['Affiliation ID'] = isset($doc['person.affiliation.id']) ? (is_array($doc['person.affiliation.id']) ? implode('; ', $doc['person.affiliation.id']) : $doc['person.affiliation.id']) : '';
        
        // OAI CERIF
        $pub['CERIF Author Affiliation'] = isset($doc['oairecerif.author.affiliation']) ? (is_array($doc['oairecerif.author.affiliation']) ? implode('; ', $doc['oairecerif.author.affiliation']) : $doc['oairecerif.author.affiliation']) : '';
        $pub['CERIF Editor Affiliation'] = isset($doc['oairecerif.editor.affiliation']) ? (is_array($doc['oairecerif.editor.affiliation']) ? implode('; ', $doc['oairecerif.editor.affiliation']) : $doc['oairecerif.editor.affiliation']) : '';
        
        // Collection/Department - resolve from collection ID
        $location_coll = isset($doc['location.coll']) ? $doc['location.coll'] : [];
        if (is_array($location_coll)) {
            $dept_names = [];
            foreach ($location_coll as $coll_id) {
                if (isset($collection_to_dept[$coll_id])) {
                    $dept_names[] = $collection_to_dept[$coll_id];
                }
            }
            $pub['Collection/Department'] = implode('; ', array_unique($dept_names));
        } else {
            $pub['Collection/Department'] = isset($collection_to_dept[$location_coll]) ? $collection_to_dept[$location_coll] : '';
        }
        
        $all_results[] = $pub;
    }
    
    return $all_results;
}

/**
 * Format results as CSV
 * @param array $data Array of publication data
 * @return string CSV formatted data
 */
function formatAsCSV($data) {
    if (empty($data)) {
        return '';
    }
    
    $output = '';
    
    // Add UTF-8 BOM for Excel compatibility
    $output .= "\xEF\xBB\xBF";
    
    // Header row
    $headers = array_keys($data[0]);
    $output .= '"' . implode('","', array_map('addQuotes', $headers)) . '"' . "\n";
    
    // Data rows
    foreach ($data as $row) {
        $output .= '"' . implode('","', array_map('addQuotes', array_values($row))) . '"' . "\n";
    }
    
    return $output;
}

/**
 * Add quotes and escape for CSV
 */
function addQuotes($value) {
    $value = str_replace('"', '""', $value);
    return $value;
}

/**
 * Format results as Plain Text (tab-separated)
 * @param array $data Array of publication data
 * @return string Plain text formatted data
 */
function formatAsPlainText($data) {
    if (empty($data)) {
        return '';
    }
    
    $output = '';
    
    // Header
    $headers = array_keys($data[0]);
    $output .= implode("\t", $headers) . "\n";
    $output .= str_repeat("-", 200) . "\n";
    
    // Data rows
    foreach ($data as $row) {
        $output .= implode("\t", array_values($row)) . "\n";
    }
    
    return $output;
}

/**
 * Format results as JSON
 * @param array $data Array of publication data
 * @return string JSON formatted data
 */
function formatAsJSON($data) {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Handle export request
 * @param string $format Export format: csv, txt, json
 * @param string $query Search query
 * @param array $filters Applied filters
 * @param int $rows Number of rows to export
 */
function handleExportRequest($format, $query, $filters, $rows = 100000) {
    // Initialize settings
    initSettings(false);
    
    // Load department mapping
    loadDepartmentMapping();
    
    // Build export query
    $solr_params = buildExportQuery($query, $filters, $rows);
    
    // Execute query
    $result = querySolr($solr_params);
    
    if ($result['error']) {
        echo json_encode(['error' => $result['error']]);
        return;
    }
    
    $docs = $result['data']['response']['docs'] ?? [];
    $total_found = $result['data']['response']['numFound'] ?? 0;
    
    // Process results
    $publications = processExportResults($docs);
    
    // Format output based on requested format
    switch (strtolower($format)) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="publications_export_' . date('Y-m-d') . '_' . $total_found . '.csv"');
            echo formatAsCSV($publications);
            break;
            
        case 'txt':
        case 'plain':
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="publications_export_' . date('Y-m-d') . '_' . $total_found . '.txt"');
            echo formatAsPlainText($publications);
            break;
            
        case 'json':
        default:
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="publications_export_' . date('Y-m-d') . '_' . $total_found . '.json"');
            echo formatAsJSON($publications);
            break;
    }
}

/**
 * Get export count without exporting (for showing user how many records will be exported)
 * @param string $query Search query
 * @param array $filters Applied filters
 * @return int Total count
 */
function getExportCount($query, $filters) {
    // Initialize settings
    initSettings(false);
    
    // Load department mapping
    loadDepartmentMapping();
    
    // Build filter query
    $filter_query = buildFilterQuery($filters);
    $count_fq = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    if (!empty($filter_query)) {
        $count_fq .= ' AND ' . $filter_query;
    }
    
    // Count query
    $count_params = [
        'q' => !empty($query) ? $query : '*:*',
        'rows' => 0,
        'wt' => 'json',
        'fq' => $count_fq
    ];
    
    $result = querySolr($count_params);
    
    if ($result['error']) {
        return 0;
    }
    
    return $result['data']['response']['numFound'] ?? 0;
}

// Handle export request if this file is called directly
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $format = isset($_GET['format']) ? $_GET['format'] : 'json';
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $rows = isset($_GET['rows']) ? intval($_GET['rows']) : 100000; // Default to large number for all records
    
    // Process filters
    $filters = [
        'author' => isset($_GET['author']) ? $_GET['author'] : [],
        'year' => isset($_GET['year']) ? $_GET['year'] : [],
        'year_from' => isset($_GET['year_from']) ? $_GET['year_from'] : '',
        'year_to' => isset($_GET['year_to']) ? $_GET['year_to'] : '',
        'type' => isset($_GET['type']) ? $_GET['type'] : [],
        'department' => isset($_GET['department']) ? $_GET['department'] : [],
        'language' => isset($_GET['language']) ? $_GET['language'] : [],
        'country' => isset($_GET['country']) ? $_GET['country'] : [],
        'source' => isset($_GET['source']) ? $_GET['source'] : [],
        'openaccess' => isset($_GET['openaccess']) ? $_GET['openaccess'] : [],
    ];
    
    handleExportRequest($format, $query, $filters, $rows);
    exit;
}

// Handle count request (for showing how many will be exported)
if (isset($_GET['action']) && $_GET['action'] === 'export_count') {
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    // Process filters
    $filters = [
        'author' => isset($_GET['author']) ? $_GET['author'] : [],
        'year' => isset($_GET['year']) ? $_GET['year'] : [],
        'year_from' => isset($_GET['year_from']) ? $_GET['year_from'] : '',
        'year_to' => isset($_GET['year_to']) ? $_GET['year_to'] : '',
        'type' => isset($_GET['type']) ? $_GET['type'] : [],
        'department' => isset($_GET['department']) ? $_GET['department'] : [],
        'language' => isset($_GET['language']) ? $_GET['language'] : [],
        'country' => isset($_GET['country']) ? $_GET['country'] : [],
        'source' => isset($_GET['source']) ? $_GET['source'] : [],
        'openaccess' => isset($_GET['openaccess']) ? $_GET['openaccess'] : [],
    ];
    
    $count = getExportCount($query, $filters);
    echo json_encode(['count' => $count]);
    exit;
}
