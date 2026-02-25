<?php
/**
 * Scholarly Gateway - Shared Helper Functions
 * Contributors: Aditya Naryan Sahoo, Shruti Rawal, Dr. Kannan P
 */

$SOLR_URL = "SOLR_URL_PLACEHOLDER";
$COMMUNITY_ID = "COMMUNITY_ID_PLACEHOLDER";
$collection_to_dept = [];

function initSettings($is_api = false) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    if ($is_api) header('Content-Type: application/json; charset=utf-8');
    ini_set('memory_limit', '-1');
    set_time_limit(0);
}

function handleFacetOnlyRequest() {
    global $SOLR_URL, $collection_to_dept;
    $filters = [
        'q' => isset($_GET['q']) ? $_GET['q'] : '',
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
    $solr_params = [
        'q' => !empty($filters['q']) ? $filters['q'] : '*:*',
        'rows' => 0, 'wt' => 'json', 'facet' => 'true', 'facet.limit' => 500, 'facet.mincount' => 1,
        'facet.field' => ['dateIssued.year', 'dc.type_facet', 'location.coll', 'language_keyword', 'dc.publisher_facet', 'person.affiliation.country_facet', 'oaire.venue.unpaywall_facet', 'author_facet']
    ];
    $solr_params['fq'] = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    $filter_query = buildFilterQuery($filters);
    if (!empty($filter_query)) $solr_params['fq'] .= ' AND ' . $filter_query;
    $result = querySolr($solr_params);
    if ($result['error']) { echo json_encode(['error' => true, 'message' => $result['message'], 'data' => null]); exit; }
    $data = $result['data'];
    $facets = processPublicationFacets($data, $filters);
    echo json_encode(['error' => false, 'data' => ['facets' => $facets]]);
    exit;
}

function loadDepartmentMapping() {
    global $collection_to_dept, $SOLR_URL, $COMMUNITY_ID;
    $exclude_communities = ['EXCLUDE_COMMUNITY_ID'];
    $params = ['q' => 'search.resourcetype:Community AND location.comm:' . $COMMUNITY_ID, 'rows' => 100, 'fl' => 'search.resourceid,dc.title'];
    $query_parts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) { foreach ($value as $v) $query_parts[] = urlencode($key) . '=' . urlencode($v); }
        else $query_parts[] = urlencode($key) . '=' . urlencode($value);
    }
    $url = $SOLR_URL . '?' . implode('&', $query_parts);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $community_to_name = [];
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        $communities = $data['response']['docs'] ?? [];
        foreach ($communities as $community) {
            $comm_id = $community['search.resourceid'] ?? '';
            $comm_title = $community['dc.title'][0] ?? '';
            if (!in_array($comm_id, $exclude_communities) && !empty($comm_id) && !empty($comm_title)) {
                $community_to_name[$comm_id] = $comm_title;
                $collection_to_dept[$comm_id] = $comm_title;
            }
        }
    }
    $params2 = ['q' => 'search.resourcetype:Collection', 'rows' => 100, 'fl' => 'search.resourceid,dc.title,location.comm'];
    $query_parts2 = [];
    foreach ($params2 as $key => $value) {
        if (is_array($value)) { foreach ($value as $v) $query_parts2[] = urlencode($key) . '=' . urlencode($v); }
        else $query_parts2[] = urlencode($key) . '=' . urlencode($value);
    }
    $url2 = $SOLR_URL . '?' . implode('&', $query_parts2);
    $ch2 = curl_init($url2);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
    $response2 = curl_exec($ch2);
    $http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    if ($http_code2 === 200 && $response2) {
        $data2 = json_decode($response2, true);
        $collections = $data2['response']['docs'] ?? [];
        foreach ($collections as $collection) {
            $coll_id = $collection['search.resourceid'] ?? '';
            $coll_title = $collection['dc.title'][0] ?? '';
            $parent_comms = $collection['location.comm'] ?? [];
            if (!empty($coll_id)) {
                $dept_name = '';
                foreach ($parent_comms as $parent_comm) {
                    if (isset($community_to_name[$parent_comm])) { $dept_name = $community_to_name[$parent_comm]; break; }
                }
                if (!empty($dept_name)) $collection_to_dept[$coll_id] = trim($dept_name);
            }
        }
    }
    $collection_to_dept['SCHOLARLY_OUTPUT_COLLECTION'] = 'Scholarly Output';
    $collection_to_dept['RESEARCHERS_COLLECTION'] = 'Researchers';
    return $collection_to_dept;
}

function getAllDynamicFacets($sampleSize = 2000) {
    global $SOLR_URL;
    $q = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    $countUrl = $SOLR_URL . '?' . http_build_query(['q' => $q, 'rows' => 0, 'wt' => 'json']);
    $ch = curl_init($countUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $countResponse = curl_exec($ch);
    curl_close($ch);
    $totalDocs = 0;
    if ($countResponse) {
        $countData = json_decode($countResponse, true);
        $totalDocs = $countData['response']['numFound'] ?? 0;
    }
    if ($totalDocs === 0) return [];
    $sampleSize = min($sampleSize, $totalDocs);
    $facetFields = ['author_facet' => 'authors', 'language_keyword' => 'languages', 'dc.publisher_facet' => 'sources', 'dc.type_facet' => 'types', 'oaire.venue.unpaywall' => 'openaccess'];
    $facetParams = ['q' => $q, 'rows' => 0, 'facet' => 'true', 'facet.limit' => 50, 'wt' => 'json'];
    foreach ($facetFields as $field => $key) $facetParams['facet.field'] = $field;
    $url = $SOLR_URL . '?' . http_build_query($facetParams);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $result = ['languages' => [], 'sources' => [], 'countries' => [], 'types' => [], 'authors' => [], 'openaccess' => []];
    if ($response) {
        $data = json_decode($response, true);
        $facetData = $data['facet_counts']['facet_fields'] ?? [];
        if (isset($facetData['author_facet'])) {
            $authorFacets = $facetData['author_facet'];
            for ($i = 0; $i < count($authorFacets); $i += 2) {
                $rawValue = $authorFacets[$i];
                $count = $authorFacets[$i + 1] ?? 0;
                $parts = preg_split('/\n\|\|\|\n/', $rawValue);
                $displayName = isset($parts[1]) ? $parts[1] : $rawValue;
                if (!empty($displayName) && $count > 0) $result['authors'][$displayName] = $count;
            }
            arsort($result['authors']);
        }
        $docFields = ['dc.language.iso' => 'languages', 'dc.source' => 'sources', 'person.affiliation.country_facet' => 'countries', 'dc.type_facet' => 'types', 'oaire.venue.unpaywall' => 'openaccess'];
        $docUrl = $SOLR_URL . '?' . http_build_query(['q' => $q, 'rows' => min($sampleSize, $totalDocs), 'fl' => implode(',', array_keys($docFields)), 'wt' => 'json']);
        $ch = curl_init($docUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $docResponse = curl_exec($ch);
        curl_close($ch);
        if ($docResponse) {
            $docData = json_decode($docResponse, true);
            $docs = $docData['response']['docs'] ?? [];
            $valueCounts = [];
            foreach ($docFields as $field => $key) $valueCounts[$key] = [];
            foreach ($docs as $doc) {
                foreach ($docFields as $field => $key) {
                    if (isset($doc[$field])) {
                        $values = is_array($doc[$field]) ? $doc[$field] : [$doc[$field]];
                        foreach ($values as $val) {
                            if (!empty($val) && strtolower($val) !== 'n/a') {
                                if (!isset($valueCounts[$key][$val])) $valueCounts[$key][$val] = 0;
                                $valueCounts[$key][$val]++;
                            }
                        }
                    }
                }
            }
            $ratio = $totalDocs / min($sampleSize, $totalDocs);
            foreach ($valueCounts as $key => $counts) {
                foreach ($counts as $val => $count) $result[$key][$val] = round($count * $ratio);
                arsort($result[$key]);
            }
        }
    }
    return $result;
}

function getAuthorsFromResearchers() {
    global $SOLR_URL;
    $researchersColl = 'RESEARCHERS_COLLECTION_ID';
    $params = ['q' => 'search.resourcetype:Item AND location.coll:' . $researchersColl, 'rows' => 500, 'fl' => 'search.resourceid,dc.title,jobTitle,orcid', 'wt' => 'json'];
    $query_parts = [];
    foreach ($params as $key => $value) $query_parts[] = urlencode($key) . '=' . urlencode($value);
    $url = $SOLR_URL . '?' . implode('&', $query_parts);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    $authors = [];
    if ($response) {
        $data = json_decode($response, true);
        $docs = $data['response']['docs'] ?? [];
        foreach ($docs as $doc) {
            $authorName = $doc['dc.title'][0] ?? '';
            $authorId = $doc['search.resourceid'] ?? '';
            $department = $doc['jobTitle'][0] ?? '';
            $orcid = $doc['orcid'][0] ?? '';
            if (!empty($authorName) && !empty($authorId)) {
                $authors[$authorName] = ['id' => $authorId, 'name' => $authorName, 'department' => $department, 'orcid' => $orcid];
            }
        }
    }
    ksort($authors);
    return $authors;
}

function getAuthorIdToNameMapping() {
    global $SOLR_URL;
    $researchersColl = 'RESEARCHERS_COLLECTION_ID';
    $params = ['q' => 'search.resourcetype:Item AND location.coll:' . $researchersColl, 'rows' => 500, 'fl' => 'search.resourceid,dc.title', 'wt' => 'json'];
    $query_parts = [];
    foreach ($params as $key => $value) $query_parts[] = urlencode($key) . '=' . urlencode($value);
    $url = $SOLR_URL . '?' . implode('&', $query_parts);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    $idToName = [];
    if ($response) {
        $data = json_decode($response, true);
        $docs = $data['response']['docs'] ?? [];
        foreach ($docs as $doc) {
            $authorId = $doc['search.resourceid'] ?? '';
            $authorName = $doc['dc.title'][0] ?? '';
            if (!empty($authorId) && !empty($authorName)) $idToName[$authorId] = $authorName;
        }
    }
    return $idToName;
}

function getAuthorFacetsFromDocs($sampleSize = 5000, $filters = []) {
    global $SOLR_URL;
    $q = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    $filterQueries = [];
    if (!empty($filters['author'])) {
        $authors = is_array($filters['author']) ? $filters['author'] : [$filters['author']];
        $authorQueries = [];
        foreach ($authors as $author) $authorQueries[] = 'dc.contributor.author:"' . addslashes(urldecode($author)) . '"';
        $filterQueries[] = '(' . implode(' OR ', $authorQueries) . ')';
    }
    if (!empty($filters['year'])) {
        $years = is_array($filters['year']) ? $filters['year'] : [$filters['year']];
        $yearQueries = [];
        foreach ($years as $year) $yearQueries[] = 'dateIssued.year:' . intval($year);
        $filterQueries[] = '(' . implode(' OR ', $yearQueries) . ')';
    }
    if (!empty($filters['type'])) {
        $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
        $typeQueries = [];
        foreach ($types as $type) $typeQueries[] = 'dc.type_facet:"' . addslashes(urldecode($type)) . '"';
        $filterQueries[] = '(' . implode(' OR ', $typeQueries) . ')';
    }
    if (!empty($filters['department'])) {
        $depts = is_array($filters['department']) ? $filters['department'] : [$filters['department']];
        $deptQueries = [];
        foreach ($depts as $dept) $deptQueries[] = 'location.comm:*' . urldecode($dept) . '*';
        $filterQueries[] = '(' . implode(' OR ', $deptQueries) . ')';
    }
    if (!empty($filters['language'])) {
        $langs = is_array($filters['language']) ? $filters['language'] : [$filters['language']];
        $langQueries = [];
        foreach ($langs as $lang) $langQueries[] = 'dc.language.iso:*' . urldecode($lang) . '*';
        $filterQueries[] = '(' . implode(' OR ', $langQueries) . ')';
    }
    if (!empty($filters['source'])) {
        $sources = is_array($filters['source']) ? $filters['source'] : [$filters['source']];
        $sourceQueries = [];
        foreach ($sources as $source) $sourceQueries[] = 'publication_grp:"' . addslashes(urldecode($source)) . '"';
        $filterQueries[] = '(' . implode(' OR ', $sourceQueries) . ')';
    }
    if (!empty($filters['openaccess'])) {
        $oaTypes = is_array($filters['openaccess']) ? $filters['openaccess'] : [$filters['openaccess']];
        $oaQueries = [];
        foreach ($oaTypes as $oa) $oaQueries[] = 'oaire.venue.unpaywall:"' . addslashes(urldecode($oa)) . '"';
        $filterQueries[] = '(' . implode(' OR ', $oaQueries) . ')';
    }
    $fq = implode(' AND ', $filterQueries);
    $queryParams = ['q' => $q, 'rows' => 0, 'wt' => 'json'];
    if (!empty($fq)) $queryParams['fq'] = $fq;
    $countUrl = $SOLR_URL . '?' . http_build_query($queryParams);
    $ch = curl_init($countUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $countResponse = curl_exec($ch);
    curl_close($ch);
    $totalDocs = 0;
    if ($countResponse) {
        $countData = json_decode($countResponse, true);
        $totalDocs = $countData['response']['numFound'] ?? 0;
    }
    if ($totalDocs === 0) return [];
    $facetParams = ['q' => $q, 'rows' => 0, 'facet' => 'true', 'facet.field' => 'author_facet', 'facet.limit' => 100, 'wt' => 'json'];
    if (!empty($fq)) $facetParams['fq'] = $fq;
    $url = $SOLR_URL . '?' . http_build_query($facetParams);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $authorCounts = [];
    if ($response) {
        $data = json_decode($response, true);
        $facetData = $data['facet_counts']['facet_fields']['author_facet'] ?? [];
        for ($i = 0; $i < count($facetData); $i += 2) {
            $rawValue = $facetData[$i];
            $count = $facetData[$i + 1] ?? 0;
            $parts = preg_split('/\n\|\|\|\n/', $rawValue);
            $displayName = isset($parts[1]) ? $parts[1] : $rawValue;
            if (!empty($displayName) && $count > 0) $authorCounts[$displayName] = $count;
        }
    }
    arsort($authorCounts);
    return $authorCounts;
}

function getDepartmentFromCollection($collection_codes, $mapping = null) {
    global $collection_to_dept;
    $departments = [];
    $dept_lookup = $mapping ?? $collection_to_dept;
    $codes = is_array($collection_codes) ? $collection_codes : array_filter(array_map('trim', explode('||', $collection_codes)));
    foreach ($codes as $code) {
        $code = trim($code);
        if (isset($dept_lookup[$code])) {
            $departments[] = $dept_lookup[$code];
            continue;
        }
        foreach ($dept_lookup as $map_code => $dept_name) {
            if (strpos($code, $map_code) !== false || strpos($map_code, $code) !== false) {
                $departments[] = $dept_name;
                break;
            }
        }
    }
    return array_values(array_unique(array_filter($departments)));
}

function querySolr($params) {
    global $SOLR_URL;
    $query_parts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) { foreach ($value as $v) $query_parts[] = urlencode($key) . '=' . urlencode($v); }
        else $query_parts[] = urlencode($key) . '=' . urlencode($value);
    }
    $url = $SOLR_URL . '?' . implode('&', $query_parts);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    if ($http_code !== 200 || $response === false) return ['error' => true, 'message' => "Solr request failed: " . $curl_error, 'http_code' => $http_code];
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return ['error' => true, 'message' => "Failed to parse Solr response: " . json_last_error_msg()];
    return ['error' => false, 'data' => $data];
}

function parseAuthors($author_string, $affiliation_string = '', $authority_string = '') {
    $authors = [];
    if (is_array($author_string)) $author_string = implode('||', $author_string);
    $affiliations = is_array($affiliation_string) ? $affiliation_string : (!empty($affiliation_string) ? array_filter(array_map('trim', explode('||', $affiliation_string))) : []);
    $authority_ids = is_array($authority_string) ? array_filter(array_map('trim', $authority_string)) : (!empty($authority_string) ? array_filter(array_map('trim', explode('||', $authority_string))) : []);
    if (empty($author_string)) return ['authors' => [], 'display' => ''];
    $parts = array_filter(array_map('trim', explode('||', $author_string)));
    $display_authors = [];
    foreach ($parts as $p) {
        $name = trim(explode("::", $p)[0]);
        $name = trim(explode("###", $name)[0]);
        if (!$name) continue;
        $authors[] = $name;
        $display_authors[] = $name;
    }
    if (count($display_authors) > 1) {
        $last_author = array_pop($display_authors);
        $display = implode("; ", $display_authors) . " and " . $last_author;
    } else {
        $display = implode("; ", $display_authors);
    }
    return ['authors' => $authors, 'display' => $display];
}

function parseMultiValue($value, $separator = '||') {
    if (empty($value)) return [];
    if (is_array($value)) return array_values(array_filter(array_map('trim', $value), function($v) { return !empty($v) && (is_string($v) ? strtolower($v) !== 'n/a' : true); }));
    return array_filter(array_map('trim', explode($separator, $value)), function($v) { return !empty($v) && (is_string($v) ? strtolower($v) !== 'n/a' : true); });
}

function extractAuthorName($rawValue) {
    if (empty($rawValue)) return '';
    $displayName = $rawValue;
    $parts = explode('|||', $displayName);
    $displayName = $parts[1] ?? $parts[0];
    if (strpos($displayName, '###') !== false) $displayName = explode('###', $displayName)[0];
    return trim($displayName);
}

function getTypePriority($type) {
    if (strpos($type, 'article') !== false) return 1;
    if (strpos($type, 'conference') !== false || strpos($type, 'proceedings') !== false) return 2;
    return 3;
}

function limit_facets($facet_array, $limit = 5) {
    if (empty($facet_array)) return [];
    $limited = [];
    $count = 0;
    foreach ($facet_array as $key => $value) {
        if ($count >= $limit) break;
        $limited[$key] = $value;
        $count++;
    }
    return $limited;
}

function buildFilterQuery($filters) {
    global $collection_to_dept;
    $filter_queries = [];
    if (!empty($filters['author'])) {
        $authors = is_array($filters['author']) ? $filters['author'] : [$filters['author']];
        $author_queries = [];
        foreach ($authors as $author) $author_queries[] = 'author_facet:"' . addslashes(urldecode($author)) . '"';
        $filter_queries[] = '(' . implode(' OR ', $author_queries) . ')';
    }
    if (!empty($filters['year'])) {
        $years = is_array($filters['year']) ? $filters['year'] : [$filters['year']];
        $year_queries = [];
        foreach ($years as $year) $year_queries[] = 'dateIssued.year:' . intval($year);
        $filter_queries[] = '(' . implode(' OR ', $year_queries) . ')';
    }
    if (!empty($filters['year_from']) && !empty($filters['year_to'])) $filter_queries[] = 'dateIssued.year:[' . intval($filters['year_from']) . ' TO ' . intval($filters['year_to']) . ']';
    if (!empty($filters['type'])) {
        $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
        $type_queries = [];
        foreach ($types as $type) $type_queries[] = 'dc.type_facet:"' . addslashes(urldecode($type)) . '"';
        $filter_queries[] = '(' . implode(' OR ', $type_queries) . ')';
    }
    if (!empty($filters['department'])) {
        $depts = is_array($filters['department']) ? $filters['department'] : [$filters['department']];
        $dept_queries = [];
        foreach ($depts as $dept) {
            $codes = array_keys($collection_to_dept, $dept);
            if (!empty($codes)) {
                foreach ($codes as $code) $dept_queries[] = 'location.comm:' . $code;
            }
        }
        if (!empty($dept_queries)) $filter_queries[] = '(' . implode(' OR ', $dept_queries) . ')';
    }
    if (!empty($filters['language'])) {
        $langs = is_array($filters['language']) ? $filters['language'] : [$filters['language']];
        $lang_queries = [];
        foreach ($langs as $lang) $lang_queries[] = 'language_keyword:"' . addslashes(urldecode($lang)) . '"';
        $filter_queries[] = '(' . implode(' OR ', $lang_queries) . ')';
    }
    if (!empty($filters['country'])) {
        $countries = is_array($filters['country']) ? $filters['country'] : [$filters['country']];
        $country_queries = [];
        foreach ($countries as $country) $country_queries[] = 'person.affiliation.country_facet:"' . addslashes(urldecode($country)) . '"';
        $filter_queries[] = '(' . implode(' OR ', $country_queries) . ')';
    }
    if (!empty($filters['source'])) {
        $sources = is_array($filters['source']) ? $filters['source'] : [$filters['source']];
        $source_queries = [];
        foreach ($sources as $source) $source_queries[] = 'dc.publisher_facet:"' . addslashes(urldecode($source)) . '"';
        $filter_queries[] = '(' . implode(' OR ', $source_queries) . ')';
    }
    if (!empty($filters['openaccess'])) {
        $oa_values = is_array($filters['openaccess']) ? $filters['openaccess'] : [$filters['openaccess']];
        $oa_queries = [];
        foreach ($oa_values as $oa) $oa_queries[] = 'oaire.venue.unpaywall:"' . addslashes(urldecode($oa)) . '"';
        $filter_queries[] = '(' . implode(' OR ', $oa_queries) . ')';
    }
    return implode(' AND ', $filter_queries);
}

function processPublicationFilters() {
    $filter_author = isset($_GET['author']) ? array_map('urldecode', (array)$_GET['author']) : [];
    $filter_year = isset($_GET['year']) ? array_map('urldecode', (array)$_GET['year']) : [];
    $filter_type = isset($_GET['type']) ? array_map('urldecode', (array)$_GET['type']) : [];
    $filter_department = isset($_GET['department']) ? array_map('urldecode', (array)$_GET['department']) : [];
    $filter_language = isset($_GET['language']) ? array_map('urldecode', (array)$_GET['language']) : [];
    $filter_country = isset($_GET['country']) ? array_map('urldecode', (array)$_GET['country']) : [];
    $filter_source = isset($_GET['source']) ? array_map('urldecode', (array)$_GET['source']) : [];
    $filter_openaccess = isset($_GET['openaccess']) ? array_map('urldecode', (array)$_GET['openaccess']) : [];
    $year_from = isset($_GET['year_from']) ? intval($_GET['year_from']) : 0;
    $year_to = isset($_GET['year_to']) ? intval($_GET['year_to']) : 0;
    if ($year_from > 0 && $year_to > 0 && $year_from <= $year_to) {
        $filter_year = [];
        for ($y = $year_from; $y <= $year_to; $y++) $filter_year[] = (string)$y;
    }
    $filter_author = array_unique(array_filter(array_map('trim', is_array($filter_author) ? $filter_author : [$filter_author])));
    $filter_year = array_unique(array_filter(array_map('trim', is_array($filter_year) ? $filter_year : [$filter_year])));
    $filter_type = array_unique(array_filter(array_map('trim', is_array($filter_type) ? $filter_type : [$filter_type])));
    $filter_department = array_unique(array_filter(array_map('trim', is_array($filter_department) ? $filter_department : [$filter_department])));
    $filter_language = array_unique(array_filter(array_map('trim', is_array($filter_language) ? $filter_language : [$filter_language])));
    $filter_country = array_unique(array_filter(array_map('trim', is_array($filter_country) ? $filter_country : [$filter_country])));
    $filter_source = array_unique(array_filter(array_map('trim', is_array($filter_source) ? $filter_source : [$filter_source])));
    $filter_openaccess = array_unique(array_filter(array_map('trim', is_array($filter_openaccess) ? $filter_openaccess : [$filter_openaccess])));
    return ['author' => $filter_author, 'year' => $filter_year, 'type' => $filter_type, 'department' => $filter_department, 'language' => $filter_language, 'country' => $filter_country, 'source' => $filter_source, 'openaccess' => $filter_openaccess, 'year_from' => $year_from, 'year_to' => $year_to];
}

function buildPublicationQuery($query, $filters, $current_page, $per_page) {
    global $collection_to_dept;
    $start = ($current_page - 1) * $per_page;
    if (!empty($query)) {
        $search_term = urldecode($query);
        $solr_params = [
            'q' => $search_term, 'defType' => 'edismax', 'qf' => 'dc.title^2 dc.contributor.author author_ac dc.source', 'mm' => '1',
            'bq' => 'dc.title:"' . $search_term . '"^10 dc.contributor.author:"' . $search_term . '"^5 author_ac:"' . $search_term . '"^5 dc.source:"' . $search_term . '"^5',
            'pf' => 'dc.title^2 dc.contributor.author author_ac dc.source', 'ps' => 1,
            'hl' => $per_page > 0 ? 'true' : 'false', 'hl.fl' => 'dc.title dc.contributor.author author_ac dc.source',
            'hl.simple.pre' => '<mark>', 'hl.simple.post' => '</mark>', 'hl.fragment.size' => 200,
            'hl.q' => $search_term, 'hl.AlternateField' => 'dc.title dc.contributor.author author_ac dc.source',
            'rows' => $per_page, 'start' => $start, 'sort' => 'dateIssued.year desc', 'wt' => 'json'
        ];
    } else {
        $solr_params = ['q' => '*:*', 'rows' => $per_page, 'start' => $start, 'sort' => 'dateIssued.year desc', 'wt' => 'json', 'hl' => $per_page > 0 ? 'true' : 'false', 'hl.fl' => 'dc.title', 'hl.simple.pre' => '<mark>', 'hl.simple.post' => '</mark>'];
    }
    $solr_params['fq'] = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    $filter_query = buildFilterQuery($filters);
    if (!empty($filter_query)) $solr_params['fq'] .= ' AND ' . $filter_query;
    if ($per_page > 0) {
        $solr_params['facet'] = 'true';
        $solr_params['facet.mincount'] = 1;
        $solr_params['facet.limit'] = 20;
        $solr_params['facet.field'] = ['dateIssued.year', 'dc.type_facet', 'location.coll', 'language_keyword', 'dc.publisher_facet', 'person.affiliation.country_facet', 'publication_grp', 'oaire.venue.unpaywall_facet', 'author_facet'];
    }
    return $solr_params;
}

function processPublicationResults($docs, $highlighting = []) {
    global $collection_to_dept;
    $all_results = [];
    $internal_researchers = getAuthorsFromResearchers();
    $internal_researcher_ids = array_column($internal_researchers, 'id');
    foreach ($docs as $doc) {
        $author_field = $doc['dc.contributor.author'] ?? $doc['dc.contributor'] ?? '';
        $affiliation_field = $doc['dc.contributor.affiliation'] ?? '';
        $authority_field = $doc['author_authority'] ?? '';
        $types = $doc['dc.type_facet'] ?? $doc['en_types_keyword'] ?? $doc['dc.type'] ?? [];
        if (is_array($types)) $types = array_map('strtolower', $types);
        elseif (!empty($types)) $types = [strtolower($types)];
        else $types = [];
        $is_thesis = in_array('m.tech', $types) || in_array('mtech', $types) || in_array('ph.d.', $types) || in_array('ph.d', $types) || in_array('phd', $types) || in_array('m.sc.', $types) || in_array('m.sc', $types) || in_array('msc', $types);
        $all_authors = is_array($author_field) ? $author_field : ($author_field ? [$author_field] : []);
        $all_affiliations = is_array($affiliation_field) ? $affiliation_field : ($affiliation_field ? [$affiliation_field] : []);
        $all_authorities = is_array($authority_field) ? $authority_field : ($authority_field ? [$authority_field] : []);
        if (!empty($doc['dc.contributor.advisor'])) {
            $advisors = is_array($doc['dc.contributor.advisor']) ? $doc['dc.contributor.advisor'] : [$doc['dc.contributor.advisor']];
            foreach ($advisors as $advisor) {
                $all_authors[] = $advisor;
                $all_affiliations[] = '';
                $all_authorities[] = '';
            }
        }
        $author_data = parseAuthors($all_authors, $all_affiliations, $all_authorities);
        $location_coll = $doc['location.coll'] ?? '';
        $dept_list = getDepartmentFromCollection($location_coll, $collection_to_dept);
        $raw_year = $doc['dateIssued.year'] ?? $doc['dc.date.issued'] ?? $doc['dc.date.accessioned'] ?? '';
        $year = '';
        if (is_array($raw_year)) { $raw_year = array_unique($raw_year); $raw_year = implode('', $raw_year); }
        if (preg_match('/^(\d{4})/', $raw_year, $matches)) $year = $matches[1];
        $types = parseMultiValue($doc['dc.type'] ?? '');
        if (empty($types)) $types = ['Unknown'];
        $lang_list = parseMultiValue($doc['dc.language.iso'] ?? '');
        $country_list = parseMultiValue($doc['dc.coverage.spatial'] ?? '');
        $openaccess_list = parseMultiValue($doc['oaire.venue.unpaywall'] ?? '');
        $item_id = $doc['search.resourceid'] ?? $doc['id'] ?? '';
        $link = !empty($item_id) ? "REPOSITORY_URL_PLACEHOLDER" . $item_id : '#';
        $doi_raw = $doc['dc.identifier.doi'] ?? '';
        $doi = is_array($doi_raw) ? implode('', $doi_raw) : ($doi_raw ?? '');
        $volume_raw = $doc['oaire.citation.volume'] ?? '';
        $volume = is_array($volume_raw) ? implode('', $volume_raw) : ($volume_raw ?? '');
        $issue_raw = $doc['oaire.citation.issue'] ?? '';
        $issue = is_array($issue_raw) ? implode('', $issue_raw) : ($issue_raw ?? '');
        $pages = '';
        $startPage = $doc['oaire.citation.startPage'] ?? '';
        $endPage = $doc['oaire.citation.endPage'] ?? '';
        if (!empty($startPage) || !empty($endPage)) {
            $startPage = is_array($startPage) ? implode('', $startPage) : $startPage;
            $endPage = is_array($endPage) ? implode('', $endPage) : $endPage;
            $pages = !empty($startPage) && !empty($endPage) ? $startPage . '-' . $endPage : ($startPage ?: $endPage);
        }
        if (empty($pages)) {
            $pageRange_raw = $doc['dc.identifier.pageRange'] ?? '';
            $pages = is_array($pageRange_raw) ? implode('', $pageRange_raw) : ($pageRange_raw ?? '');
        }
        $title = $doc['dc.title'] ?? '';
        if (is_array($title)) $title = implode(' ', $title);
        if (!is_string($title) || trim($title) === '') $title = 'Untitled';
        $doc_id = $doc['search.resourceid'] ?? $doc['id'] ?? '';
        $doc_highlights = $highlighting[$doc_id] ?? [];
        $highlighted_title = $doc_highlights['dc.title'][0] ?? $doc_highlights['title'][0] ?? '';
        $search_term = $GLOBALS['current_search_query'] ?? '';
        if (empty($highlighted_title) && !empty($search_term)) {
            $processed_title = ucfirst(strtolower($title));
            $highlighted_title = preg_replace('/(' . preg_quote($search_term, '/') . ')/i', '<mark>$1</mark>', $processed_title);
            if ($highlighted_title === $processed_title) $highlighted_title = preg_replace('/\b(' . preg_quote($search_term, '/') . ')/i', '<mark>$1</mark>', $processed_title);
        }
        $authors_display = $author_data['display'];
        $highlighted_authors = $authors_display;
        if (!empty($search_term) && !empty($authors_display)) {
            $highlighted_authors = preg_replace('/(' . preg_quote($search_term, '/') . ')/i', '<mark>$1</mark>', $authors_display);
            if ($highlighted_authors === $authors_display) $highlighted_authors = preg_replace('/\b(' . preg_quote($search_term, '/') . ')/i', '<mark>$1</mark>', $authors_display);
        }
        $source = isset($doc['dc.source']) ? (is_array($doc['dc.source']) ? implode(' ', $doc['dc.source']) : $doc['dc.source']) : '';
        $highlighted_source = $source;
        if (!empty($search_term) && !empty($source)) {
            $highlighted_source = preg_replace('/(' . preg_quote($search_term, '/') . ')/i', '<mark>$1</mark>', $source);
            if ($highlighted_source === $source) $highlighted_source = preg_replace('/\b(' . preg_quote($search_term, '/') . ')/i', '<mark>$1</mark>', $source);
        }
        $display_title = !empty($highlighted_title) ? $highlighted_title : ucfirst(strtolower($title));
        $all_results[] = [
            'title' => $display_title, 'title_plain' => ucfirst(strtolower($title)), 'highlighted_title' => $highlighted_title,
            'authors' => $highlighted_authors, 'authors_plain' => $authors_display, 'author_list' => $author_data['authors'],
            'year' => $year ?: 'N/A', 'type' => implode(' || ', $types), 'type_list' => $types,
            'journal' => $highlighted_source, 'journal_plain' => $source, 'doi' => $doi, 'volume' => $volume, 'issue' => $issue, 'pages' => $pages,
            'link' => $link, 'id' => $doc['search.resourceid'] ?? $doc['id'] ?? '', 'department' => implode('; ', $dept_list), 'dept_list' => $dept_list,
            'language' => isset($doc['dc.language.iso']) ? (is_array($doc['dc.language.iso']) ? implode(', ', $doc['dc.language.iso']) : $doc['dc.language.iso']) : '',
            'lang_list' => array_values($lang_list),
            'country' => isset($doc['dc.coverage.spatial']) ? (is_array($doc['dc.coverage.spatial']) ? implode(', ', $doc['dc.coverage.spatial']) : $doc['dc.coverage.spatial']) : '',
            'country_list' => array_values($country_list), 'openaccess_list' => array_values($openaccess_list)
        ];
    }
    return $all_results;
}

function processPublicationFacets($data, $filters = []) {
    $raw_facets = $data['facet_counts']['facet_fields'] ?? [];
    if (empty($raw_facets) && isset($data['facets'])) $raw_facets = $data['facets'] ?? [];
    $facets = ['authors' => [], 'years' => [], 'types' => [], 'departments' => [], 'languages' => [], 'countries' => [], 'sources' => [], 'openaccess' => []];
    if (isset($raw_facets['dateIssued.year'])) {
        $year_facets = $raw_facets['dateIssued.year'];
        $year_counts = [];
        for ($i = 0; $i < count($year_facets); $i += 2) {
            $year_value = $year_facets[$i];
            $count = $year_facets[$i + 1] ?? 0;
            if (!empty($year_value) && is_numeric($year_value)) {
                $year = intval($year_value);
                if ($year >= 2000 && $year <= 2030) $year_counts[$year] = ($year_counts[$year] ?? 0) + $count;
            }
        }
        krsort($year_counts, SORT_NUMERIC);
        $facets['years'] = $year_counts;
    }
    if (isset($raw_facets['dc.type_facet'])) {
        $type_facets = $raw_facets['dc.type_facet'];
        $type_counts = [];
        for ($i = 0; $i < count($type_facets); $i += 2) {
            $type = $type_facets[$i];
            $count = $type_facets[$i + 1] ?? 0;
            if (!empty($type) && is_string($type)) $type_counts[$type] = ($type_counts[$type] ?? 0) + $count;
        }
        arsort($type_counts);
        $facets['types'] = $type_counts;
    }
    if (isset($raw_facets['author_facet'])) {
        $author_facets = $raw_facets['author_facet'];
        for ($i = 0; $i < count($author_facets); $i += 2) {
            $rawValue = $author_facets[$i];
            $count = $author_facets[$i + 1] ?? 0;
            $parts = preg_split('/\n\|\|\|\n/', $rawValue);
            $displayName = isset($parts[1]) ? $parts[1] : $rawValue;
            if (!empty($displayName) && $count > 0) $facets['authors'][$displayName] = $count;
        }
        arsort($facets['authors']);
    }
    if (empty($facets['authors']) && isset($raw_facets['dc.contributor.author'])) {
        $author_facets = $raw_facets['dc.contributor.author'];
        for ($i = 0; $i < count($author_facets); $i += 2) {
            $author = $author_facets[$i];
            $count = $author_facets[$i + 1] ?? 0;
            if (!empty($author)) $facets['authors'][(string)$author] = ($facets['authors'][(string)$author] ?? 0) + $count;
        }
        arsort($facets['authors']);
    }
    if (isset($raw_facets['language_keyword'])) {
        $lang_facets = $raw_facets['language_keyword'];
        for ($i = 0; $i < count($lang_facets); $i += 2) {
            $lang = $lang_facets[$i];
            $count = $lang_facets[$i + 1] ?? 0;
            if (!empty($lang)) $facets['languages'][(string)$lang] = $count;
        }
        arsort($facets['languages']);
    }
    if (isset($raw_facets['dc.publisher_facet'])) {
        $source_facets = $raw_facets['dc.publisher_facet'];
        for ($i = 0; $i < count($source_facets); $i += 2) {
            $source = $source_facets[$i];
            $count = $source_facets[$i + 1] ?? 0;
            if (!empty($source) && $source !== 'N/A') $facets['sources'][(string)$source] = ($facets['sources'][(string)$source] ?? 0) + $count;
        }
        arsort($facets['sources']);
    }
    if (isset($raw_facets['person.affiliation.country_facet'])) {
        $country_facets = $raw_facets['person.affiliation.country_facet'];
        for ($i = 0; $i < count($country_facets); $i += 2) {
            $country = $country_facets[$i];
            $count = $country_facets[$i + 1] ?? 0;
            if (!empty($country) && $country !== 'N/A') $facets['countries'][(string)$country] = ($facets['countries'][(string)$country] ?? 0) + $count;
        }
        arsort($facets['countries']);
    }
    if (isset($raw_facets['location.coll'])) {
        global $collection_to_dept;
        $collection_facets = $raw_facets['location.coll'];
        for ($i = 0; $i < count($collection_facets); $i += 2) {
            $collection_code = $collection_facets[$i];
            $count = $collection_facets[$i + 1] ?? 0;
            if (!empty($collection_code)) {
                $dept_name = $collection_to_dept[$collection_code] ?? null;
                if ($dept_name) $facets['departments'][(string)$dept_name] = ($facets['departments'][(string)$dept_name] ?? 0) + $count;
            }
        }
        arsort($facets['departments']);
    }
    if (isset($raw_facets['oaire.venue.unpaywall_facet'])) {
        $oa_facets = $raw_facets['oaire.venue.unpaywall_facet'];
        for ($i = 0; $i < count($oa_facets); $i += 2) {
            $oa = $oa_facets[$i];
            $count = $oa_facets[$i + 1] ?? 0;
            if (!empty($oa)) $facets['openaccess'][(string)$oa] = ($facets['openaccess'][(string)$oa] ?? 0) + $count;
        }
        arsort($facets['openaccess']);
    }
    return $facets;
}

function build_page_url($base_params, $page) {
    $params = [];
    foreach ($base_params as $key => $value) {
        if ($key === 'page' || $value === '' || $value === null) continue;
        if (is_array($value)) {
            $filtered = array_filter($value, function($v) { return $v !== '' && $v !== null; });
            if (!empty($filtered)) $params[$key] = $filtered;
        } else {
            $params[$key] = $value;
        }
    }
    if ($page > 1) $params['page'] = $page;
    return '?' . http_build_query($params, '', '&');
}

function render_facet_items($facet_name, $facet_data, $filter_array, $input_name) {
    $initial_count = 5;
    $total_count = count($facet_data);
    $index = 0;
    echo '<div class="facet-items" id="' . $facet_name . '-items" data-initial="' . $initial_count . '">';
    foreach ($facet_data as $value => $count) {
        $trimmed_value = trim($value);
        $display_value = $trimmed_value;
        if ($facet_name === 'openaccess') $display_value = ucfirst(strtolower($trimmed_value));
        $is_checked = false;
        if (is_array($filter_array)) {
            foreach ($filter_array as $filter_val) {
                if (strcasecmp(trim($filter_val), $trimmed_value) === 0) { $is_checked = true; break; }
            }
        }
        $hidden_class = ($index >= $initial_count) ? ' hidden-facet' : '';
        echo '<label class="facet-checkbox' . $hidden_class . ($is_checked ? ' active' : '') . '" data-index="' . $index . '">';
        echo '<input type="checkbox" name="' . $input_name . '[]" value="' . htmlspecialchars($trimmed_value) . '" ' . ($is_checked ? 'checked' : '') . '>';
        echo '<span>' . htmlspecialchars($display_value) . '</span>';
        echo '<span class="count">(' . $count . ')</span>';
        echo '</label>';
        $index++;
    }
    echo '</div>';
    if ($total_count > $initial_count) echo '<button type="button" class="see-more-btn" data-facet="' . $facet_name . '" data-shown="' . $initial_count . '" data-total="' . $total_count . '">See More</button>';
}

function getAllAuthorsWithCounts($limit = 500) {
    global $SOLR_URL;
    $q = 'search.resourcetype:Item AND location.coll:COLLECTION_ID_PLACEHOLDER';
    $params = ['q' => $q, 'rows' => 0, 'facet' => 'true', 'facet.field' => 'author_facet', 'facet.limit' => $limit, 'wt' => 'json'];
    $url = $SOLR_URL . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $authors = [];
    if ($response) {
        $data = json_decode($response, true);
        $facetData = $data['facet_counts']['facet_fields']['author_facet'] ?? [];
        for ($i = 0; $i < count($facetData); $i += 2) {
            $rawValue = $facetData[$i];
            $count = $facetData[$i + 1] ?? 0;
            $parts = preg_split('/\n\|\|\|\n/', $rawValue);
            $displayName = isset($parts[1]) ? $parts[1] : $rawValue;
            if (!empty($displayName) && $count > 0) $authors[$displayName] = $count;
        }
    }
    arsort($authors);
    return $authors;
}
