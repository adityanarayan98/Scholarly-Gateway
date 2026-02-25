<?php
/**
 * Scholarly Gateway - Publications API
 * Handles data fetching and faceted search using Apache Solr
 * Returns JSON response for AJAX frontend
 * 
 * Contributors: Aditya Naryan Sahoo, Shruti Rawal, Dr. Kannan P
 */

// Include shared helper functions
require_once 'helpers.php';

// Initialize settings (headers, memory/time limits)
initSettings(true);

// Load department mapping dynamically from Solr
loadDepartmentMapping();

// Default rows per page
$DEFAULT_ROWS = 25;
$MAX_ROWS = 500;

$action = isset($_GET['action']) ? $_GET['action'] : 'search';

// Handle facet-only request for modal
if ($action === 'facets') {
    handleFacetOnlyRequest();
}

// ============================================
// INPUT PROCESSING
// Parse and validate request parameters
// ============================================

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : $DEFAULT_ROWS;
$per_page = min($per_page, $MAX_ROWS);

// Build filters array
$filters = [
    'q' => $query,
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

// ============================================
// PAGINATION VALIDATION
// Get total count to validate page number
// ============================================

// Build filter query for initial count query
$filter_query = buildFilterQuery($filters);
// UPDATE COLLECTION ID FOR YOUR INSTALLATION
$count_fq = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
if (!empty($filter_query)) {
    $count_fq .= ' AND ' . $filter_query;
}

// First query: get total count to validate page number
$count_params = [
    'q' => !empty($query) ? $query : '*:*',
    'rows' => 0,
    'wt' => 'json',
    'fq' => $count_fq
];

$count_result = querySolr($count_params);
$total_count = 0;
if (!$count_result['error'] && isset($count_result['data']['response']['numFound'])) {
    $total_count = $count_result['data']['response']['numFound'];
}

// Calculate total pages
$total_pages = max(1, ceil($total_count / $per_page));

// Adjust page if it exceeds total pages
if ($page > $total_pages) {
    $page = $total_pages;
}

// ============================================
// MAIN QUERY EXECUTION
// Build and execute main Solr query
// ============================================

// Build main query params - using DisMax for better search when there's a query
if (!empty($query)) {
    $solr_params = [
        'q' => $query,
        'defType' => 'edismax',
        'qf' => 'dc.title^2 dc.contributor.author author_ac dc.source',
        'mm' => '1',
        'pf' => 'dc.title^2 dc.contributor.author author_ac dc.source',
        'ps' => 1,
        'hl' => 'true',
        'hl.fl' => 'dc.title dc.contributor.author author_ac dc.source',
        'hl.simple.pre' => '<mark>',
        'hl.simple.post' => '</mark>',
        'hl.fragment.size' => 200,
        'rows' => $per_page,
        'start' => ($page - 1) * $per_page,
        'wt' => 'json'
    ];
} else {
    // No query - return all results
    $solr_params = [
        'q' => '*:*',
        'rows' => $per_page,
        'start' => ($page - 1) * $per_page,
        'wt' => 'json'
    ];
}

// Filter to get Scholarly Output items only - UPDATE FOR YOUR INSTALLATION
$solr_params['fq'] = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';

// Add filter query
$filter_query = buildFilterQuery($filters);
if (!empty($filter_query)) {
    if (!empty($solr_params['fq'])) {
        $solr_params['fq'] .= ' AND ' . $filter_query;
    } else {
        $solr_params['fq'] = $filter_query;
    }
}

// Add faceting - limit to 100 facet values
$solr_params['facet'] = 'true';
$solr_params['facet.limit'] = 100;
$solr_params['facet.field'] = [
    'dateIssued.year',
    'dc.type_facet',
    'location.coll',
    'language_keyword',
    'dc.publisher_facet',
    'person.affiliation.country_facet',
    'publication_grp',
    'oaire.venue.unpaywall_facet',
    'author_facet'
];

// Make request to Solr
$result = querySolr($solr_params);

if ($result['error']) {
    echo json_encode([
        'error' => true,
        'message' => $result['message'],
        'data' => null
    ]);
    exit;
}

$data = $result['data'];

// ============================================
// DATA PROCESSING
// Transform Solr documents and facets
// ============================================

// Process results - use total_count from validated count query
$docs = $data['response']['docs'] ?? [];
$total = $total_count;

// Get highlighting data if available
$highlighting = $data['highlighting'] ?? [];

// Process each document
$processed = [];
foreach ($docs as $doc) {
    // Get document ID for highlighting lookup
    $doc_id = $doc['search.resourceid'] ?? $doc['id'] ?? '';
    
    // Get highlighted snippets for this document
    $doc_highlights = $highlighting[$doc_id] ?? [];
    
    // Use highlighted title if available, otherwise use regular title
    $highlighted_title = $doc_highlights['dc.title'][0] ?? '';
    $title = $doc['dc.title'][0] ?? '';
    $display_title = !empty($highlighted_title) ? $highlighted_title : $title;
    
    // Get highlighted author if available
    $highlighted_author = $doc_highlights['author_ac'][0] ?? 
                          $doc_highlights['dc.contributor.author'][0] ?? '';
    
    // Combine authors and advisors
    $all_authors = isset($doc['dc.contributor.author']) && is_array($doc['dc.contributor.author']) 
        ? $doc['dc.contributor.author'] 
        : (isset($doc['dc.contributor.author']) && $doc['dc.contributor.author'] ? [$doc['dc.contributor.author']] : []);
    
    $all_author_affiliations = [];
    if (isset($doc['dc.contributor.author_affiliation'])) {
        $all_author_affiliations = is_array($doc['dc.contributor.author_affiliation']) 
            ? $doc['dc.contributor.author_affiliation'] 
            : [$doc['dc.contributor.author_affiliation']];
    }
    
    $all_author_authorities = isset($doc['author_authority']) && is_array($doc['author_authority']) 
        ? $doc['author_authority'] 
        : (isset($doc['author_authority']) && $doc['author_authority'] ? [$doc['author_authority']] : []);
    
    // Add advisors to the author list
    if (!empty($doc['dc.contributor.advisor'])) {
        $advisors = is_array($doc['dc.contributor.advisor']) 
            ? $doc['dc.contributor.advisor'] 
            : [$doc['dc.contributor.advisor']];
        
        foreach ($advisors as $advisor) {
            $all_authors[] = $advisor;
            $all_author_affiliations[] = '';
            $all_author_authorities[] = '';
        }
    }
    
    // Parse authors
    $author_data = parseAuthors(
        $all_authors,
        $all_author_affiliations,
        $all_author_authorities
    );
    
    // Get departments from collection
    $departments = getDepartmentFromCollection(
        $doc['location.coll'] ?? '',
        $collection_to_dept
    );
    
    // Parse other multi-value fields
    $types = parseMultiValue($doc['dc.type'] ?? '');
    $languages = parseMultiValue($doc['dc.language.iso'] ?? '');
    $countries = parseMultiValue($doc['dc.coverage.spatial'] ?? '');
    $sources = parseMultiValue($doc['dc.source'] ?? '');
    
    $processed[] = [
        'id' => $doc['search.resourceid'] ?? '',
        'title' => $display_title,
        'title_plain' => $title,
        'highlighted_title' => $highlighted_title,
        'highlighted_author' => $highlighted_author,
        'authors' => $author_data['authors'],
        'author_display' => $author_data['display'],
        'year' => $doc['dateIssued.year'][0] ?? '',
        'type' => $types[0] ?? '',
        'types' => $types,
        'departments' => $departments,
        'languages' => $languages,
        'countries' => $countries,
        'sources' => $sources,
        'handle' => $doc['dc.identifier.uri'][0] ?? '',
        'doi' => $doc['dc.identifier.doi'][0] ?? '',
    ];
}

// Process facets
$facets = [];
if (isset($data['facet_counts']['facet_fields'])) {
    $raw_facets = $data['facet_counts']['facet_fields'];
    
    // Process year facets
    $facets['years'] = [];
    if (isset($raw_facets['dateIssued.year'])) {
        $year_facets = $raw_facets['dateIssued.year'];
        for ($i = 0; $i < count($year_facets); $i += 2) {
            $facets['years'][$year_facets[$i]] = $year_facets[$i + 1];
        }
    }
    krsort($facets['years']);
    
    // Process type facets
    $facets['types'] = [];
    if (isset($raw_facets['dc.type_facet'])) {
        $type_facets = $raw_facets['dc.type_facet'];
        for ($i = 0; $i < count($type_facets); $i += 2) {
            $facets['types'][$type_facets[$i]] = $type_facets[$i + 1];
        }
    }
    
    // Process department facets
    $facets['departments'] = [];
    if (isset($raw_facets['location.coll'])) {
        $coll_facets = $raw_facets['location.coll'];
        for ($i = 0; $i < count($coll_facets); $i += 2) {
            $code = $coll_facets[$i];
            $count = $coll_facets[$i + 1];
            $dept_name = trim($collection_to_dept[$code] ?? ('Collection ' . substr($code, 0, 8)));
            if ($dept_name !== 'Scholarly Output') {
                $facets['departments'][$dept_name] = $count;
            }
        }
    }
    
    // Process language facets
    $facets['languages'] = [];
    if (isset($raw_facets['language_keyword'])) {
        $lang_facets = $raw_facets['language_keyword'];
        for ($i = 0; $i < count($lang_facets); $i += 2) {
            $lang = $lang_facets[$i];
            $count = $lang_facets[$i + 1] ?? 0;
            if (!empty($lang)) {
                $facets['languages'][(string)$lang] = $count;
            }
        }
        arsort($facets['languages']);
    }
    
    // Process source facets
    $facets['sources'] = [];
    if (isset($raw_facets['dc.publisher_facet'])) {
        $source_facets = $raw_facets['dc.publisher_facet'];
        for ($i = 0; $i < count($source_facets); $i += 2) {
            $source = $source_facets[$i];
            $count = $source_facets[$i + 1] ?? 0;
            if (!empty($source) && $source !== 'N/A') {
                $facets['sources'][(string)$source] = ($facets['sources'][(string)$source] ?? 0) + $count;
            }
        }
        arsort($facets['sources']);
    }
    
    // Process countries
    $facets['countries'] = [];
    if (isset($raw_facets['person.affiliation.country_facet'])) {
        $country_facets = $raw_facets['person.affiliation.country_facet'];
        for ($i = 0; $i < count($country_facets); $i += 2) {
            $country = $country_facets[$i];
            $count = $country_facets[$i + 1] ?? 0;
            if (!empty($country) && $country !== 'N/A') {
                $facets['countries'][(string)$country] = ($facets['countries'][(string)$country] ?? 0) + $count;
            }
        }
        arsort($facets['countries']);
    }
    
    // Process open access facets
    $facets['openaccess'] = [];
    if (isset($raw_facets['oaire.venue.unpaywall_facet'])) {
        $oa_facets = $raw_facets['oaire.venue.unpaywall_facet'];
        for ($i = 0; $i < count($oa_facets); $i += 2) {
            $oa = $oa_facets[$i];
            $count = $oa_facets[$i + 1] ?? 0;
            if (!empty($oa)) {
                $facets['openaccess'][(string)$oa] = ($facets['openaccess'][(string)$oa] ?? 0) + $count;
            }
        }
        arsort($facets['openaccess']);
    }
    
    // Process author facets
    $facets['authors'] = [];
    if (isset($raw_facets['author_facet'])) {
        $author_facets = $raw_facets['author_facet'];
        for ($i = 0; $i < count($author_facets); $i += 2) {
            $rawValue = $author_facets[$i];
            $count = $author_facets[$i + 1] ?? 0;
            
            $parts = preg_split('/\n\|\|\|\n/', $rawValue);
            $displayName = isset($parts[1]) ? $parts[1] : $rawValue;
            
            if (!empty($displayName) && $count > 0) {
                $facets['authors'][$displayName] = $count;
            }
        }
        arsort($facets['authors']);
    }
}

// ============================================
// RESPONSE
// Build and return JSON response
// ============================================

// Build pagination
$pagination = [
    'current_page' => $page,
    'per_page' => $per_page,
    'total' => $total_count,
    'total_pages' => $total_pages
];

// Return JSON response
echo json_encode([
    'error' => false,
    'data' => [
        'results' => $processed,
        'facets' => $facets,
        'pagination' => $pagination
    ]
]);
