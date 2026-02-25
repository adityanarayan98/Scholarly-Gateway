<?php
/**
 * Scholarly Gateway - Publications Search Page
 * Combines Solr API backend with full frontend UI
 * 
 * Contributors: Aditya Naryan Sahoo, Shruti Rawal, Dr. Kannan P
 */

// Disable OPcache to ensure fresh code is loaded
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// Disable browser caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Include shared helper functions
require_once 'helpers.php';

// Initialize settings
initSettings(false);

// Load department mapping dynamically from Solr
loadDepartmentMapping();

// Load authors from Researchers community for facet
$authors_list = getAuthorsFromResearchers();

// ============================================
// SECTION 1: INITIALIZATION
// ============================================

// Pagination settings
$per_page_options = [25, 50, 100, 500];
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 25;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// ============================================
// SECTION 2: INPUT PROCESSING
// ============================================

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Process all filters from GET parameters
$filters = processPublicationFilters();
$filter_author = $filters['author'];
$filter_year = $filters['year'];
$filter_type = $filters['type'];
$filter_department = $filters['department'];
$filter_language = $filters['language'];
$filter_country = $filters['country'];
$filter_source = $filters['source'];
$filter_openaccess = $filters['openaccess'];
$year_from = $filters['year_from'];
$year_to = $filters['year_to'];

// ============================================
// SECTION 3: PAGINATION VALIDATION
// ============================================

// Build a count-only query to get total and validate page number
$count_only_params = buildPublicationQuery($query, $filters, 1, 0);
$count_result = querySolr($count_only_params);
$initial_total = 0;
if (!$count_result['error'] && isset($count_result['data']['response']['numFound'])) {
    $initial_total = $count_result['data']['response']['numFound'];
}

// Calculate total pages and validate current page
$total_pages_estimate = max(1, ceil($initial_total / $per_page));

// Adjust page if it exceeds available pages
if ($current_page > $total_pages_estimate) {
    $current_page = $total_pages_estimate;
}

// ============================================
// SECTION 4: QUERY EXECUTION
// ============================================

// Build Solr query using helper function
$solr_params = buildPublicationQuery($query, $filters, $current_page, $per_page);

// Execute main query
$result = querySolr($solr_params);

$load_error = null;
$solr_loaded = false;
$all_results = [];
$total_found = 0;
$facets = [];

if ($result['error']) {
    $load_error = $result['message'];
} else {
    $solr_loaded = true;
    $data = $result['data'];
    
    $highlighting = $data['highlighting'] ?? [];
    
    // Set global for manual highlighting
    $GLOBALS['current_search_query'] = $query;
    
    $all_results = processPublicationResults($data['response']['docs'] ?? [], $highlighting);
    
    $total_found = $data['response']['numFound'] ?? count($all_results);
    
    $facets = processPublicationFacets($data, $filters);
}

// Get min/max years for JavaScript
$years_list = !empty($facets['years']) ? array_keys($facets['years']) : [];
$min_year = !empty($years_list) ? min($years_list) : date('Y') - 10;
$max_year = !empty($years_list) ? max($years_list) : date('Y');

// Build base parameters for pagination
$base_params = $_GET;
unset($base_params['page']);
if($per_page != 25) $base_params['per_page'] = $per_page;

// Calculate pagination values
$start_result = (($current_page - 1) * $per_page) + 1;
$end_result = min($current_page * $per_page, $total_found);

// Sort results by year descending, with type prioritization
if (!empty($all_results)) {
    usort($all_results, function($a, $b) {
        $yearA = $a['year'] ?? '';
        $yearB = $b['year'] ?? '';
        
        $isValidA = !empty($yearA) && $yearA !== 'N/A';
        $isValidB = !empty($yearB) && $yearB !== 'N/A';
        
        if (!$isValidA && !$isValidB) return 0;
        if (!$isValidA) return 1;
        if (!$isValidB) return -1;
        
        if ($yearA === $yearB) {
            $typeA = strtolower($a['type'] ?? '');
            $typeB = strtolower($b['type'] ?? '');
            
            $priorityA = getTypePriority($typeA);
            $priorityB = getTypePriority($typeB);
            
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }
            
            return 0;
        }
        
        return $yearB <=> $yearA;
    });
}

// Pagination calculation
$total_results = $total_found ?? 0;
$total_pages = max(1, ceil($total_results / $per_page));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $per_page;

$paginated_results = $all_results;

// Build base URL for filters
$base_params = $_GET;
unset($base_params['page']);
if(isset($per_page) && $per_page != 25) $base_params['per_page'] = $per_page;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.5, maximum-scale=5.0, minimum-scale=0.5, user-scalable=yes">
    <meta name="description" content="Research Publication Portal - Search and browse scholarly publications, research papers, and academic works. Filter by year, author, department, type, and more.">
    <title>Research Publication</title>
    
    <script>
        // Store limited facet data for sidebar (top 5 each) - modal will fetch full data
        var facetDataStore = {
            <?php if(!empty($facets['types'])): ?>'type': <?= json_encode(limit_facets($facets['types'], 5)) ?><?php if(!empty($facets['authors']) || !empty($facets['departments']) || !empty($facets['languages']) || !empty($facets['countries']) || !empty($facets['sources']) || !empty($facets['openaccess'])): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($facets['authors'])): ?>'author': <?= json_encode(limit_facets($facets['authors'], 5)) ?><?php if(!empty($facets['departments']) || !empty($facets['languages']) || !empty($facets['countries']) || !empty($facets['sources']) || !empty($facets['openaccess'])): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($facets['departments'])): ?>'department': <?= json_encode(limit_facets($facets['departments'], 5)) ?><?php if(!empty($facets['languages']) || !empty($facets['countries']) || !empty($facets['sources']) || !empty($facets['openaccess'])): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($facets['languages'])): ?>'language': <?= json_encode(limit_facets($facets['languages'], 5)) ?><?php if(!empty($facets['countries']) || !empty($facets['sources']) || !empty($facets['openaccess'])): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($facets['countries'])): ?>'country': <?= json_encode(limit_facets($facets['countries'], 5)) ?><?php if(!empty($facets['sources']) || !empty($facets['openaccess'])): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($facets['sources'])): ?>'source': <?= json_encode(limit_facets($facets['sources'], 5)) ?><?php if(!empty($facets['openaccess'])): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($facets['openaccess'])): ?>'openaccess': <?= json_encode(limit_facets($facets['openaccess'], 5)) ?><?php endif; ?>
        };
        var facetFilterStore = {
            <?php if(!empty($filter_type)): ?>'type': <?= json_encode(array_map('urldecode', $filter_type)) ?><?php if(!empty($filter_author) || !empty($filter_department) || !empty($filter_language) || !empty($filter_country) || !empty($filter_source) || !empty($filter_openaccess)): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($filter_author)): ?>'author': <?= json_encode(array_map('urldecode', $filter_author)) ?><?php if(!empty($filter_department) || !empty($filter_language) || !empty($filter_country) || !empty($filter_source) || !empty($filter_openaccess)): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($filter_department)): ?>'department': <?= json_encode(array_map('urldecode', $filter_department)) ?><?php if(!empty($filter_language) || !empty($filter_country) || !empty($filter_source) || !empty($filter_openaccess)): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($filter_language)): ?>'language': <?= json_encode(array_map('urldecode', $filter_language)) ?><?php if(!empty($filter_country) || !empty($filter_source) || !empty($filter_openaccess)): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($filter_country)): ?>'country': <?= json_encode(array_map('urldecode', $filter_country)) ?><?php if(!empty($filter_source) || !empty($filter_openaccess)): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($filter_source)): ?>'source': <?= json_encode(array_map('urldecode', $filter_source)) ?><?php if(!empty($filter_openaccess)): ?>,<?php endif; ?><?php endif; ?>
            <?php if(!empty($filter_openaccess)): ?>'openaccess': <?= json_encode(array_map('urldecode', $filter_openaccess)) ?><?php endif; ?>
        };
        
        function toggleFacet(header) {
            const content = header.nextElementSibling;
            const toggle = header.querySelector('.facet-toggle');
            
            content.classList.toggle('collapsed');
            toggle.classList.toggle('collapsed');
        }
        
        // Toggle sidebar (burger menu) for mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('open');
        }

        function toggleYearView(view) {
            const rangeView = document.getElementById('year-range-view');
            const individualView = document.getElementById('year-individual-view');
            const buttons = document.querySelectorAll('.year-view-btn');
            
            buttons.forEach(btn => {
                if (btn.getAttribute('data-view') === view) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            if (view === 'range') {
                rangeView.style.display = 'block';
                individualView.style.display = 'none';
            } else {
                rangeView.style.display = 'none';
                individualView.style.display = 'block';
            }
        }

        // Apply year range from input fields
        function applyYearRangeFromInputs() {
            const yearFromInput = document.getElementById('year-from');
            const yearToInput = document.getElementById('year-to');
            
            if (!yearFromInput || !yearToInput) {
                return;
            }
            
            const minYear = <?= $min_year ?>;
            const maxYear = <?= $max_year ?>;
            
            const yearFrom = parseInt(yearFromInput.value) || minYear;
            const yearTo = parseInt(yearToInput.value) || maxYear;
            
            let currentUrl = window.location.href;
            let url = new URL(currentUrl);
            
            url.searchParams.delete('year_from');
            url.searchParams.delete('year_to');
            url.searchParams.delete('year');
            
            url.searchParams.set('year_from', yearFrom);
            url.searchParams.set('year_to', yearTo);
            
            window.location.href = url.toString();
        }

        // Auto-submit form when any checkbox changes
        function setupAutoSubmit() {
            const form = document.getElementById('facet-form');
            if (!form) return;
            
            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const label = this.closest('label');
                    if (label) {
                        if (this.checked) {
                            label.classList.add('active');
                        } else {
                            label.classList.remove('active');
                        }
                    }
                    
                    let url = new URL(window.location.href);
                    url.searchParams.delete('page');
                    
                    let checkboxName = this.name;
                    if (checkboxName.endsWith('[]')) {
                        checkboxName = checkboxName.slice(0, -2);
                    }
                    const allCheckboxes = form.querySelectorAll('input[name="' + checkboxName + '[]"]:checked');
                    
                    url.searchParams.delete(checkboxName + '[]');
                    url.searchParams.delete(checkboxName);
                    
                    allCheckboxes.forEach(cb => {
                        url.searchParams.append(checkboxName + '[]', cb.value);
                    });
                    
                    window.location.href = url.toString();
                });
            });
        }

        // See More functionality
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.see-more-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const facetName = this.getAttribute('data-facet');
                    const container = document.getElementById(facetName + '-items');
                    if (!container) return;
                    
                    const items = container.querySelectorAll('.hidden-facet');
                    items.forEach(item => {
                        item.classList.remove('hidden-facet');
                    });
                    this.style.display = 'none';
                });
            });
            
            setupAutoSubmit();
            
            // Year Range Slider
            const sliderMin = document.getElementById('year-slider-min');
            const sliderMax = document.getElementById('year-slider-max');
            const inputFrom = document.getElementById('year-from');
            const inputTo = document.getElementById('year-to');
            
            if (sliderMin && sliderMax && inputFrom && inputTo) {
                sliderMin.addEventListener('input', function() {
                    let minVal = parseInt(this.value);
                    let maxVal = parseInt(sliderMax.value);
                    if (minVal > maxVal) {
                        minVal = maxVal;
                        this.value = minVal;
                    }
                    inputFrom.value = minVal;
                });
                
                sliderMax.addEventListener('input', function() {
                    let minVal = parseInt(sliderMin.value);
                    let maxVal = parseInt(this.value);
                    if (maxVal < minVal) {
                        maxVal = minVal;
                        this.value = maxVal;
                    }
                    inputTo.value = maxVal;
                });
                
                inputFrom.addEventListener('change', function() {
                    let val = parseInt(this.value);
                    const min = parseInt(this.min);
                    const max = parseInt(this.max);
                    if (val < min) val = min;
                    if (val > max) val = max;
                    if (val > parseInt(inputTo.value)) val = parseInt(inputTo.value);
                    this.value = val;
                    sliderMin.value = val;
                });
                
                inputTo.addEventListener('change', function() {
                    let val = parseInt(this.value);
                    const min = parseInt(this.min);
                    const max = parseInt(this.max);
                    if (val < min) val = min;
                    if (val > max) val = max;
                    if (val < parseInt(inputFrom.value)) val = parseInt(inputFrom.value);
                    this.value = val;
                    sliderMax.value = val;
                });
            }
        });
        
        // Facet Modal Functions
        function openFacetModal(facetName, facetTitle) {
            const currentFilters = facetFilterStore[facetName] || [];
            const modal = document.getElementById('facet-modal');
            const modalTitle = document.getElementById('facet-modal-title');
            const modalBody = document.getElementById('facet-modal-body');
            
            modalTitle.textContent = facetTitle;
            
            modalBody.innerHTML = '<div class="loading">Loading...</div>';
            modal.style.display = 'flex';
            
            const urlParams = new URLSearchParams(window.location.search);
            let apiUrl = 'publications_api.php?action=facets';
            
            if (urlParams.has('q')) {
                apiUrl += '&q=' + encodeURIComponent(urlParams.get('q'));
            }
            
            const filterParams = ['type', 'author', 'department', 'language', 'country', 'source', 'openaccess'];
            filterParams.forEach(param => {
                const filters = facetFilterStore[param] || [];
                if (filters.length > 0) {
                    filters.forEach(f => {
                        apiUrl += '&' + param + '[]=' + encodeURIComponent(f);
                    });
                }
            });
            
            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        modalBody.innerHTML = '<div class="error">Error loading facets: ' + (data.message || 'Unknown error') + '</div>';
                        return;
                    }
                    
                    const facets = data.data.facets;
                    const facetFieldMap = {
                        'type': 'types',
                        'author': 'authors',
                        'department': 'departments',
                        'language': 'languages',
                        'country': 'countries',
                        'source': 'sources',
                        'openaccess': 'openaccess'
                    };
                    
                    const facetData = facets[facetFieldMap[facetName]] || {};
                    
                    const itemCount = Object.keys(facetData).length;
                    const colsClass = itemCount > 20 ? 'cols-3' : '';
                    
                    let html = '<input type="text" id="facet-search" class="facet-search" placeholder="Search ' + facetTitle + '..." onkeyup="filterFacetModal()">';
                    html += '<div class="facet-modal-items ' + colsClass + '" id="facet-modal-items">';
                    
                    for (const [value, count] of Object.entries(facetData)) {
                        const trimmedValue = value.trim();
                        const isChecked = currentFilters.some(f => f.trim() === trimmedValue);
                        const activeClass = isChecked ? 'active' : '';
                        html += '<label class="facet-modal-item ' + activeClass + '" data-value="' + encodeURIComponent(trimmedValue) + '" data-search="' + escapeHtml(trimmedValue.toLowerCase()) + '">';
                        html += '<input type="checkbox" ' + (isChecked ? 'checked' : '') + '> ';
                        html += '<span class="name">' + escapeHtml(trimmedValue) + '</span>';
                        html += '<span class="count">(' + count + ')</span>';
                        html += '</label>';
                    }
                    html += '</div>';
                    
                    modalBody.innerHTML = html;
                    
                    modalBody.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                        checkbox.addEventListener('change', function() {
                            const label = this.closest('.facet-modal-item');
                            if (this.checked) {
                                label.classList.add('active');
                            } else {
                                label.classList.remove('active');
                            }
                            submitFacetFilter(facetName);
                        });
                    });
                })
                .catch(error => {
                    console.error('Error fetching facets:', error);
                    modalBody.innerHTML = '<div class="error">Error loading facets: ' + error.message + '</div>';
                });
        }
        
        function closeFacetModal() {
            document.getElementById('facet-modal').style.display = 'none';
        }
        
        function filterFacetModal() {
            const searchInput = document.getElementById('facet-search');
            const filter = searchInput.value.toLowerCase();
            const items = document.querySelectorAll('#facet-modal-items .facet-modal-item');
            
            items.forEach(item => {
                const searchText = item.getAttribute('data-search');
                if (searchText.includes(filter)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        function submitFacetFilter(facetName) {
            const modalBody = document.getElementById('facet-modal-body');
            const checked = modalBody.querySelectorAll('input[type="checkbox"]:checked');
            
            let url = new URL(window.location.href);
            
            url.searchParams.delete(facetName + '[]');
            url.searchParams.delete(facetName);
            
            checked.forEach(cb => {
                url.searchParams.append(facetName + '[]', cb.closest('.facet-modal-item').dataset.value);
            });
            
            window.location.href = url.toString();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close modal when clicking outside
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('facet-modal');
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeFacetModal();
                }
            });
            
            setupAltmetricEventHandlers();
        });
        
        // Altmetric badge event handlers
        function setupAltmetricEventHandlers() {
            setTimeout(function() {
                const badgeLines = document.querySelectorAll('.metrics-badge-line');
                
                badgeLines.forEach(function(badgeLine) {
                    const altmetricBadge = badgeLine.querySelector('.altmetric-embed');
                    
                    if (altmetricBadge) {
                        altmetricBadge.addEventListener('altmetric:show', function() {
                            badgeLine.style.display = 'flex';
                            
                            badgeLine.style.opacity = '0';
                            badgeLine.style.transform = 'translateY(5px)';
                            
                            void badgeLine.offsetWidth;
                            badgeLine.style.opacity = '1';
                            badgeLine.style.transform = 'translateY(0)';
                        });
                        
                        altmetricBadge.addEventListener('altmetric:hide', function() {
                            const dimensionsBadge = badgeLine.querySelector('.__dimensions_badge_embed__');
                            const plumxBadge = badgeLine.querySelector('.plumx-details-badge');
                            
                            const hasDimensionsContent = dimensionsBadge && dimensionsBadge.innerHTML.trim() !== '';
                            const hasPlumXContent = plumxBadge && plumxBadge.innerHTML.trim() !== '';
                            
                            if (!hasDimensionsContent && !hasPlumXContent) {
                                badgeLine.style.opacity = '0';
                                badgeLine.style.transform = 'translateY(5px)';
                                
                                setTimeout(function() {
                                    badgeLine.style.display = 'none';
                                }, 300);
                            }
                        });
                    }
                });
            }, 2000);
        }
    </script>
    
    <style>
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; }
        .header { background: #002d54; color: white; padding: 20px 50px; }
        
        .search-container {
            background: #f4f7f9;
            padding: 20px 50px;
            border-bottom: 1px solid #ddd;
        }
        .search-bar-fullwidth {
            display: flex;
            max-width: 1500px;
            margin: 0 auto;
        }
        .search-bar-fullwidth input {
            flex: 1;
            padding: 15px 20px;
            border: 2px solid #005596;
            border-radius: 4px 0 0 4px;
            font-size: 16px;
            outline: none;
            background: white;
        }
        .search-bar-fullwidth input:focus {
            border-color: #ff6a00;
        }
        .search-bar-fullwidth button {
            background: #ff6a00;
            color: white;
            border: 2px solid #ff6a00;
            border-left: none;
            padding: 0 40px;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        .search-bar-fullwidth button:hover {
            background: #e55f00;
            border-color: #e55f00;
        }
        
        .wrapper { display: grid; grid-template-columns: 300px 1fr; gap: 30px; padding: 30px 50px; max-width: 1500px; margin: auto; }
        .sidebar { background: white; padding: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); align-self: start; }
        .facet-title { font-weight: bold; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 5px; margin: 20px 0 8px; color: #555; }
        .facet-title:first-child { margin-top: 0; }
        
        .facet-checkbox { display: flex; justify-content: space-between; align-items: center; font-size: 14px; color: #005596; cursor: pointer; margin-bottom: 2px; padding: 1px 0; }
        .facet-checkbox:hover { color: #ff6a00; }
        .facet-checkbox.active { font-weight: bold; color: #0056b3; }
        .facet-checkbox input[type="checkbox"] { margin-right: 6px; cursor: pointer; flex-shrink: 0; }
        .facet-checkbox span:first-of-type { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 8px; }
        .count { color: #888; font-size: 11px; flex-shrink: 0; text-align: right; min-width: 30px; }
        
        .year-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 3px 0;
        }
        .year-item input[type="checkbox"] {
            margin-right: 4px;
        }
        .year-label {
            font-size: 13px;
            min-width: 45px;
            flex-shrink: 0;
        }
        .year-bar-container {
            flex: 1;
            height: 12px;
            background: #f0f0f0;
            border-radius: 2px;
            overflow: hidden;
            min-width: 40px;
        }
        .year-bar {
            height: 100%;
            background: #005596;
            border-radius: 2px;
            transition: width 0.3s ease;
        }
        .year-item:hover .year-bar {
            background: #ff6a00;
        }
        .year-item.active .year-bar {
            background: #ff6a00;
        }
        .year-count {
            font-size: 11px;
            color: #666;
            min-width: 30px;
            text-align: right;
            flex-shrink: 0;
        }
        
        .year-view-toggle {
            display: flex;
            margin-bottom: 10px;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #ddd;
        }
        .year-view-btn {
            flex: 1;
            padding: 6px 10px;
            border: none;
            background: #f5f5f5;
            color: #666;
            font-size: 12px;
            cursor: pointer;
        }
        .year-view-btn.active {
            background: #005596;
            color: white;
        }
        .year-histogram {
            display: flex;
            align-items: flex-end;
            height: 60px;
            gap: 2px;
            margin-bottom: 10px;
            padding: 5px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .year-histogram-bar {
            flex: 1;
            background: #005596;
            border-radius: 2px 2px 0 0;
            min-height: 2px;
            cursor: pointer;
        }
        .year-histogram-bar:hover {
            background: #ff6a00;
        }
        .year-slider-container {
            position: relative;
            height: 30px;
            margin-bottom: 10px;
        }
        .year-slider {
            position: absolute;
            width: 100%;
            height: 5px;
            background: #ddd;
            border-radius: 3px;
            outline: none;
            -webkit-appearance: none;
            pointer-events: none;
        }
        .year-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background: #005596;
            border-radius: 50%;
            cursor: pointer;
            pointer-events: auto;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        #year-slider-min { z-index: 2; }
        #year-slider-max { z-index: 1; }
        .year-range-inputs {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .year-input-group {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .year-input-group label {
            font-size: 12px;
            color: #666;
        }
        .year-input-group input {
            flex: 1;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
            width: 60px;
        }
        .year-apply-btn {
            width: 100%;
            padding: 8px;
            background: #ff6a00;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .card { background: white; padding: 20px; margin-bottom: 15px; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        
        .content { flex: 1; display: flex; flex-direction: column; }
        .content-main { flex: 1; min-width: 0; }
        .meta-row { display: flex; flex-direction: row; gap: 20px; }
        .meta-info { flex: 1; min-width: 0; }
        .content-badges { display: flex; flex-direction: column; justify-content: flex-start; align-items: flex-end; min-width: 200px; }
        .content-badges .metrics-badge-line { display: flex; flex-direction: row; gap: 8px; }
        .content a {
            color: #005596;
            font-weight: bold;
            text-decoration: none;
            font-size: 17px;
        }
        .content a:hover { text-decoration: underline; }
        
        .card-authors {
            font-size: 14px;
            margin-top: 5px;
        }
        .card-meta {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
        }
        .card-meta strong { 
            color: #333; 
            font-size: 14px;
        }
        .card-meta a[href*="doi.org"] {
            font-size: 14px;
            color: #005596;
            text-decoration: none;
        }
        .card-meta a[href*="doi.org"]:hover {
            text-decoration: underline;
        }
        mark { background-color: #87CEEB; padding: 1px 3px; border-radius: 3px; font-weight: normal; }
        
        .active-filters { margin-bottom: 15px; }
        .filter-tag { display: inline-flex; align-items: center; gap: 6px; background: #e3f2fd; color: #005596; padding: 4px 10px; border-radius: 12px; font-size: 12px; margin-right: 8px; margin-bottom: 5px; }
        .filter-tag strong { color: #002d54; }
        .filter-tag a.clear-btn { color: #666; text-decoration: none; font-weight: bold; font-size: 14px; line-height: 1; }
        .filter-tag a.clear-btn:hover { color: #ff6a00; }
        
        .facet-section { margin-bottom: 5px; }
        .facet-header { display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; }
        .facet-toggle { font-size: 10px; color: #888; transition: transform 0.2s; margin-left: 8px; }
        .facet-toggle.collapsed { transform: rotate(-90deg); }
        .facet-content { max-height: none; overflow-y: visible; transition: max-height 0.3s ease; }
        .facet-content.collapsed { max-height: 0; }
        
        .see-more-btn { 
            display: block; 
            width: 100%; 
            text-align: center; 
            padding: 5px; 
            margin-top: 5px; 
            background: #f0f0f0; 
            border: none; 
            border-radius: 3px; 
            color: #005596; 
            font-size: 11px; 
            cursor: pointer; 
        }
        .see-more-btn:hover { background: #e0e0e0; }
        .hidden-facet { display: none; }
        .facet-items { max-height: 350px; overflow-y: auto; }
        
        .view-all-btn {
            display: block;
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            background: #f0f0f0;
            border: none;
            border-radius: 3px;
            color: #005596;
            font-size: 12px;
            cursor: pointer;
            text-align: center;
        }
        .view-all-btn:hover { background: #e0e0e0; color: #ff6a00; }
        
        .facet-modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .facet-modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .facet-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            background: #f5f5f5;
            border-radius: 8px 8px 0 0;
        }
        .facet-modal-title {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .facet-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            font-weight: bold;
            color: #666;
            cursor: pointer;
            line-height: 1;
        }
        .facet-modal-close:hover { color: #000; }
        .facet-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .facet-search {
            width: 100%;
            padding: 10px 15px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .facet-modal-items {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .facet-modal-items.cols-3 {
            grid-template-columns: repeat(3, 1fr);
        }
        .facet-modal-item {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            border: 1px solid #eee;
            border-radius: 4px;
            cursor: pointer;
        }
        .facet-modal-item:hover {
            background: #f5f5f5;
        }
        .facet-modal-item.active {
            background: #e3f2fd;
            border-color: #005596;
            font-weight: bold;
        }
        .facet-modal-item.active input[type="checkbox"] {
            transform: scale(1.2);
        }
        .facet-modal-item input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }
        .facet-modal-item span.name {
            flex: 1;
            color: #005596;
            font-size: 14px;
        }
        .facet-modal-item span.count {
            color: #888;
            font-size: 12px;
            margin-left: 8px;
        }
        
        .error-message {
            background: #ffebee;
            color: #c62828;
            padding: 15px 20px;
            border-radius: 4px;
            border-left: 4px solid #c62828;
            margin-bottom: 20px;
        }
        
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .results-info { color: #666; font-size: 14px; }
        .results-info strong { color: #002d54; }
        .per-page-selector { display: flex; align-items: center; gap: 8px; }
        .per-page-selector label { color: #666; font-size: 13px; }
        .per-page-selector select {
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        .export-selector { display: flex; align-items: center; gap: 8px; margin-left: 20px; }
        .export-selector label { color: #666; font-size: 13px; }
        .export-selector select {
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        .export-selector select:hover { border-color: #005596; }
        
        .metrics-badge-line {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            vertical-align: middle;
        }
        
        .crossref-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 6px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 11px;
            color: #333;
            font-weight: 500;
        }
        .crossref-badge:hover {
            background: #e9ecef;
        }
        .crossref-count {
            font-weight: bold;
            color: #002d54;
        }
        .crossref-hidden {
            display: none !important;
        }
        
        .card-meta .metrics-badge-line {
            margin-top: 8px;
        }
        
        .badges-left {
            margin-bottom: 10px;
        }
        
        .badges-bottom {
            margin-top: auto;
            display: flex;
            justify-content: flex-end;
        }
        
        .pagination-container {
            margin-top: 30px;
            padding: 20px;
            background: white;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .pagination-nav {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        .pagination-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #f0f0f0;
            color: #005596;
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
        }
        .pagination-btn:hover:not(.disabled) {
            background: #005596;
            color: white;
        }
        .pagination-btn.disabled {
            background: #e0e0e0;
            color: #999;
            cursor: not-allowed;
        }
        .pagination-page {
            display: inline-block;
            min-width: 36px;
            padding: 8px 12px;
            background: #f0f0f0;
            color: #005596;
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
        }
        .pagination-page:hover {
            background: #005596;
            color: white;
        }
        .pagination-page.active {
            background: #ff6a00;
            color: white;
        }
        .pagination-ellipsis {
            color: #999;
            font-size: 13px;
            padding: 8px 4px;
        }
        .pagination-info {
            margin-top: 10px;
            color: #666;
            font-size: 12px;
        }
        
        .burger-menu {
            display: none !important;
        }
        
        .sidebar-overlay {
            display: none !important;
        }
        
        .sidebar-close {
            display: none !important;
        }
        
        .sidebar {
            position: static !important;
            left: auto !important;
            width: auto !important;
            height: auto !important;
            overflow: visible !important;
            z-index: auto !important;
            border-radius: 4px !important;
        }
        
        .wrapper {
            grid-template-columns: 300px 1fr !important;
        }
        
        .main-wrapper {
            overflow-x: auto;
            min-width: 100%;
        }
    </style>
    
    <!-- Altmetric Donut Badge Script -->
    <script async type="text/javascript" src="https://d1bxh8uas1mnw7.cloudfront.net/assets/embed.js"></script>
    
    <!-- Dimensions Badge Script -->
    <script async src="https://badge.dimensions.ai/badge.js"></script>
    
    <!-- PlumX Badge Script -->
    <script async type="text/javascript" src="https://cdn.plu.mx/widget-details.js"></script>
</head>
<body>

<div class="header"><h1>Research Publication</h1></div>

<!-- FULL-WIDTH SEARCH BAR -->
<div class="search-container">
    <form class="search-bar-fullwidth">
        <input type="text" id="search-q" name="q" placeholder="Search publications..." value="<?= htmlspecialchars($query) ?>" aria-label="Search publications">
        <?php if($filter_author): ?><input type="hidden" name="author" value="<?= htmlspecialchars(is_array($filter_author) ? implode(',', $filter_author) : $filter_author) ?>"><?php endif; ?>
        <?php if($filter_year): ?><input type="hidden" name="year" value="<?= htmlspecialchars(is_array($filter_year) ? implode(',', $filter_year) : $filter_year) ?>"><?php endif; ?>
        <?php if($filter_type): ?><input type="hidden" name="type" value="<?= htmlspecialchars(is_array($filter_type) ? implode(',', $filter_type) : $filter_type) ?>"><?php endif; ?>
        <?php if($filter_department): ?><input type="hidden" name="department" value="<?= htmlspecialchars(is_array($filter_department) ? implode(',', $filter_department) : $filter_department) ?>"><?php endif; ?>
        <?php if($filter_language): ?><input type="hidden" name="language" value="<?= htmlspecialchars(is_array($filter_language) ? implode(',', $filter_language) : $filter_language) ?>"><?php endif; ?>
        <?php if($filter_country): ?><input type="hidden" name="country" value="<?= htmlspecialchars(is_array($filter_country) ? implode(',', $filter_country) : $filter_country) ?>"><?php endif; ?>
        <?php if($filter_source): ?><input type="hidden" name="source" value="<?= htmlspecialchars(is_array($filter_source) ? implode(',', $filter_source) : $filter_source) ?>"><?php endif; ?>
        <?php if($filter_openaccess): ?><input type="hidden" name="openaccess" value="<?= htmlspecialchars(is_array($filter_openaccess) ? implode(',', $filter_openaccess) : $filter_openaccess) ?>"><?php endif; ?>
        <?php if($per_page != 25): ?><input type="hidden" name="per_page" value="<?= $per_page ?>"><?php endif; ?>
        <button type="submit">SEARCH</button>
    </form>
    <?php if(!empty($query)): ?>
    <div class="search-info" style="margin-top: 10px; padding: 8px 15px; background-color: #e3f2fd; border-radius: 4px; color: #0d47a1; font-size: 14px;">
        <strong>You searched for:</strong> "<?= htmlspecialchars($query) ?>"
    </div>
    <?php endif; ?>
</div>

<!-- Mobile: Burger Menu for Sidebar -->
<button class="burger-menu" onclick="toggleSidebar()">
    <span>☰</span> Filters
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="main-wrapper">
<div class="wrapper">
<aside class="sidebar">
    <button type="button" class="sidebar-close" onclick="toggleSidebar()">✕ Close</button>
    <form method="GET" id="facet-form">
        <input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>">
        
        <?php if($solr_loaded): ?>
        <!-- YEAR FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Year</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <div class="year-view-toggle">
                    <button type="button" class="year-view-btn active" data-view="range" onclick="toggleYearView('range')">Range</button>
                    <button type="button" class="year-view-btn" data-view="individual" onclick="toggleYearView('individual')">Individual</button>
                </div>
                
                <div id="year-range-view" class="year-view">
                    <div class="year-histogram">
                        <?php
                        $max_year_count = !empty($facets['years']) ? max($facets['years']) : 0;
                        $years_asc = $facets['years'];
                        ksort($years_asc, SORT_STRING);
                        foreach($years_asc as $yr => $count):
                            $bar_height = $max_year_count > 0 ? ($count / $max_year_count * 100) : 0;
                        ?>
                            <div class="year-histogram-bar" data-year="<?= $yr ?>" style="height: <?= $bar_height ?>%;" title="<?= $yr ?>: <?= $count ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="year-slider-container">
                        <input type="range" id="year-slider-min" min="<?= $min_year ?>" max="<?= $max_year ?>" value="<?= $year_from > 0 ? $year_from : $min_year ?>" class="year-slider" data-year-slider="from">
                        <input type="range" id="year-slider-max" min="<?= $min_year ?>" max="<?= $max_year ?>" value="<?= $year_to > 0 ? $year_to : $max_year ?>" class="year-slider" data-year-slider="to">
                    </div>
                    <div class="year-range-inputs">
                        <div class="year-input-group">
                            <label>From:</label>
                            <input type="number" id="year-from" value="<?= $year_from > 0 ? $year_from : $min_year ?>" min="<?= $min_year ?>" max="<?= $max_year ?>" data-year-input="from">
                        </div>
                        <div class="year-input-group">
                            <label>To:</label>
                            <input type="number" id="year-to" value="<?= $year_to > 0 ? $year_to : $max_year ?>" min="<?= $min_year ?>" max="<?= $max_year ?>" data-year-input="to">
                        </div>
                    </div>
                    <button type="button" class="year-apply-btn" onclick="applyYearRangeFromInputs()">Apply Range</button>
                </div>
                
                <div id="year-individual-view" class="year-view" style="display: none;">
                    <?php
                    foreach($facets['years'] as $yr => $count):
                        $is_checked = in_array($yr, $filter_year);
                    ?>
                        <label class="facet-checkbox year-item <?= $is_checked ? 'active' : '' ?>">
                            <input type="checkbox" name="year[]" value="<?= htmlspecialchars($yr) ?>" 
                                   <?= $is_checked ? 'checked' : '' ?>>
                            <span class="year-label"><?= htmlspecialchars($yr) ?></span>
                            <span class="year-count"><?= $count ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- TYPE FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Publication Type</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['types'])): ?>
                    <?php render_facet_items('type', $facets['types'], $filter_type, 'type'); ?>
                    <?php if(count($facets['types']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('type', 'Publication Type')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No types available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- DEPARTMENT FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Department</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['departments'])): ?>
                    <?php render_facet_items('department', $facets['departments'], $filter_department, 'department'); ?>
                    <?php if(count($facets['departments']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('department', 'Department')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No departments available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- AUTHOR FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Author</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['authors'])): ?>
                    <?php render_facet_items('author', $facets['authors'], $filter_author, 'author'); ?>
                    <?php if(count($facets['authors']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('author', 'Author')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No authors available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- SOURCE TITLE FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Source Title</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['sources'])): ?>
                    <?php render_facet_items('source', $facets['sources'], $filter_source, 'source'); ?>
                    <?php if(count($facets['sources']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('source', 'Source Title')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No sources available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- COUNTRY FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Country</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['countries'])): ?>
                    <?php render_facet_items('country', $facets['countries'], $filter_country, 'country'); ?>
                    <?php if(count($facets['countries']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('country', 'Country')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No countries available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- OPEN ACCESS TYPE FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Open Access Type</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['openaccess'])): ?>
                    <?php render_facet_items('openaccess', $facets['openaccess'], $filter_openaccess, 'openaccess'); ?>
                    <?php if(count($facets['openaccess']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('openaccess', 'Open Access Type')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No open access types available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- LANGUAGE FACET -->
        <div class="facet-section">
            <div class="facet-header" onclick="toggleFacet(this)">
                <div class="facet-title" style="margin: 0; border: none; flex: 1;">Language</div>
                <span class="facet-toggle">▼</span>
            </div>
            <div class="facet-content">
                <?php if(!empty($facets['languages'])): ?>
                    <?php render_facet_items('language', $facets['languages'], $filter_language, 'language'); ?>
                    <?php if(count($facets['languages']) > 5): ?>
                    <button type="button" class="view-all-btn" onclick="openFacetModal('language', 'Language')">View All</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #999; padding: 10px; font-size: 12px;">No languages available</p>
                <?php endif; ?>
            </div>
        </div>
        <hr style="border: 1px solid #5b4be9; margin: 15px 0; opacity: 0.5;">
        <div style="height: 10px;"></div>

        <!-- CLEAR FILTERS -->
        <?php if(!empty($filter_year) || !empty($filter_type) || !empty($filter_author) || !empty($filter_department) || !empty($filter_language) || !empty($filter_country) || !empty($filter_source) || !empty($filter_openaccess) || $query): ?>
            <a href="?" style="color:red; font-size:12px; font-weight:bold; text-decoration:none; display:block; margin-top:10px; padding-top:10px; border-top:1px solid #eee; text-align:center;">✕ Clear All Filters</a>
        <?php endif; ?>
        <?php else: ?>
        <p style="color: #999; font-size: 13px; text-align: center; padding: 20px;">
            Filters unavailable while data is loading.
        </p>
        <?php endif; ?>
    </form>
</aside>

<!-- FACET POPUP MODAL -->
<div id="facet-modal" class="facet-modal" style="display: none;">
    <div class="facet-modal-content">
        <div class="facet-modal-header">
            <span class="facet-modal-title" id="facet-modal-title">Select Filters</span>
            <button type="button" class="facet-modal-close" onclick="closeFacetModal()">&times;</button>
        </div>
        <div class="facet-modal-body" id="facet-modal-body">
        </div>
    </div>
</div>

<main>
    <?php if($load_error): ?>
    <div class="error-message">
        <h3>⚠ Data Loading Error</h3>
        <p><?= htmlspecialchars($load_error) ?></p>
    </div>
    <?php endif; ?>
    
    <!-- ACTIVE FILTERS DISPLAY -->
    <?php if($filter_year || $filter_type || $filter_author || $filter_department || $filter_language || $filter_country || $filter_source || $filter_openaccess): ?>
    <div class="active-filters">
        <strong style="font-size:12px; color:#666;">Active Filters:</strong><br>
    <?php if($filter_year):
        sort($filter_year, SORT_NUMERIC);
        $is_consecutive = true;
        for ($i = 1; $i < count($filter_year); $i++) {
            if ($filter_year[$i] != $filter_year[$i-1] + 1) {
                $is_consecutive = false;
                break;
            }
        }
        if (count($filter_year) > 2 && $is_consecutive):
            $min_year = min($filter_year);
            $max_year = max($filter_year);
        ?>
            <span class="filter-tag">
                Year: <strong><?= $min_year ?> To <?= $max_year ?></strong>
                <a href="?<?= http_build_query(array_diff_key($base_params, ['year' => 1, 'year_from' => 1, 'year_to' => 1])) ?>" class="clear-btn" title="Remove filter">×</a>
            </span>
        <?php else:
            foreach ($filter_year as $yr): ?>
            <span class="filter-tag">
                Year: <strong><?= htmlspecialchars($yr) ?></strong>
                <a href="?<?php
                    $new_years = array_diff($filter_year, [$yr]);
                    $new_params = $base_params;
                    if (empty($new_years)) {
                        unset($new_params['year']);
                    } else {
                        $new_params['year'] = array_values($new_years);
                    }
                    echo http_build_query($new_params);
                ?>" class="clear-btn" title="Remove filter">×</a>
            </span>
            <?php endforeach;
        endif;
    endif; ?>
    
    <?php if($filter_type): foreach ($filter_type as $type): ?>
        <span class="filter-tag">
            Type: <strong><?= htmlspecialchars(urldecode($type)) ?></strong>
            <a href="?<?php
                $new_types = array_diff($filter_type, [$type]);
                $new_params = $base_params;
                if (empty($new_types)) {
                    unset($new_params['type']);
                } else {
                    $new_params['type'] = array_values($new_types);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    
    <?php if($filter_author): foreach ($filter_author as $author): ?>
        <span class="filter-tag">
            Author: <strong><?= htmlspecialchars(urldecode($author)) ?></strong>
            <a href="?<?php
                $new_authors = array_diff($filter_author, [$author]);
                $new_params = $base_params;
                if (empty($new_authors)) {
                    unset($new_params['author']);
                } else {
                    $new_params['author'] = array_values($new_authors);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    
    <?php if($filter_department): foreach ($filter_department as $dept): ?>
        <span class="filter-tag">
            Dept: <strong><?= htmlspecialchars(urldecode($dept)) ?></strong>
            <a href="?<?php
                $new_depts = array_diff($filter_department, [$dept]);
                $new_params = $base_params;
                if (empty($new_depts)) {
                    unset($new_params['department']);
                } else {
                    $new_params['department'] = array_values($new_depts);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    
    <?php if($filter_language): foreach ($filter_language as $lang): ?>
        <span class="filter-tag">
            Lang: <strong><?= htmlspecialchars(urldecode($lang)) ?></strong>
            <a href="?<?php
                $new_langs = array_diff($filter_language, [$lang]);
                $new_params = $base_params;
                if (empty($new_langs)) {
                    unset($new_params['language']);
                } else {
                    $new_params['language'] = array_values($new_langs);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    
    <?php if($filter_country): foreach ($filter_country as $country): ?>
        <span class="filter-tag">
            Country: <strong><?= htmlspecialchars(urldecode($country)) ?></strong>
            <a href="?<?php
                $new_countries = array_diff($filter_country, [$country]);
                $new_params = $base_params;
                if (empty($new_countries)) {
                    unset($new_params['country']);
                } else {
                    $new_params['country'] = array_values($new_countries);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    
    <?php if($filter_source): foreach ($filter_source as $source): ?>
        <span class="filter-tag">
            Source: <strong><?= htmlspecialchars(urldecode($source)) ?></strong>
            <a href="?<?php
                $new_sources = array_diff($filter_source, [$source]);
                $new_params = $base_params;
                if (empty($new_sources)) {
                    unset($new_params['source']);
                } else {
                    $new_params['source'] = array_values($new_sources);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    
    <?php if($filter_openaccess): foreach ($filter_openaccess as $oa): ?>
        <span class="filter-tag">
            OA: <strong><?= htmlspecialchars(urldecode($oa)) ?></strong>
            <a href="?<?php
                $new_oa = array_diff($filter_openaccess, [$oa]);
                $new_params = $base_params;
                if (empty($new_oa)) {
                    unset($new_params['openaccess']);
                } else {
                    $new_params['openaccess'] = array_values($new_oa);
                }
                echo http_build_query($new_params);
            ?>" class="clear-btn" title="Remove filter">×</a>
        </span>
    <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- RESULTS HEADER -->
    <div class="results-header">
        <div class="results-info">
            <?php if($solr_loaded && $total_results > 0): ?>
                Showing <strong><?= $start_result ?></strong> to <strong><?= $end_result ?></strong> of <strong><?= $total_results ?></strong> results
            <?php elseif($solr_loaded): ?>
                <?php 
                $search_info = [];
                if (!empty($query)) $search_info[] = 'search: "' . htmlspecialchars($query) . '"';
                if (!empty($filter_type)) $search_info[] = 'type: ' . implode(', ', array_map('htmlspecialchars', $filter_type));
                if (!empty($filter_year)) $search_info[] = 'year: ' . implode(', ', array_map('htmlspecialchars', $filter_year));
                if (!empty($filter_department)) $search_info[] = 'department: ' . implode(', ', array_map('htmlspecialchars', $filter_department));
                if (!empty($filter_author)) $search_info[] = 'author: ' . implode(', ', array_map('htmlspecialchars', $filter_author));
                if (!empty($filter_country)) $search_info[] = 'country: ' . implode(', ', array_map('htmlspecialchars', $filter_country));
                if (!empty($filter_source)) $search_info[] = 'source: ' . implode(', ', array_map('htmlspecialchars', $filter_source));
                if (!empty($filter_language)) $search_info[] = 'language: ' . implode(', ', array_map('htmlspecialchars', $filter_language));
                if (!empty($filter_openaccess)) $search_info[] = 'open access: ' . implode(', ', array_map('htmlspecialchars', $filter_openaccess));
                ?>
                No results found<?php if(!empty($search_info)): ?> for <?= implode(', ', $search_info) ?><?php endif; ?>. Try adjusting your filters.
            <?php else: ?>
                Loading...
            <?php endif; ?>
        </div>
        <div class="per-page-selector">
            <label for="per-page-select">Results per page:</label>
            <select id="per-page-select" onchange="window.location.href='?<?= http_build_query(array_merge($base_params, ['per_page' => ''])) ?>' + this.value + '&page=1'">
                <?php foreach($per_page_options as $opt): ?>
                    <option value="<?= $opt ?>" <?= $per_page == $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="export-selector">
            <label for="export-format">Export:</label>
            <select id="export-format" onchange="handleExport(this.value)">
                <option value="">Select Format</option>
                <option value="csv">CSV</option>
                <option value="json">JSON</option>
                <option value="txt">Text</option>
            </select>
        </div>
    </div>
    
    <!-- RESULTS -->
    <div id="results">
        <?php if($solr_loaded && !empty($paginated_results)): ?>
            <?php foreach($paginated_results as $index => $pub): ?>
                <div class="card">
                    <div class="content">
                        <?php 
                        $title_to_display = !empty($pub['highlighted_title']) ? $pub['highlighted_title'] : htmlspecialchars($pub['title']);
                        ?>
                        <a href="<?= htmlspecialchars($pub['link']) ?>" target="_blank"><?= $title_to_display ?></a>
                        <div class="card-authors"><?= $pub['authors'] ?></div>
                        <div class="meta-row">
                            <div class="meta-info">
                                <?php
                                $line1_parts = [];
                                
                                if (!empty($pub['type_list'])) {
                                    $type_list = is_array($pub['type_list']) ? $pub['type_list'] : explode(' || ', $pub['type_list']);
                                    $type_value = htmlspecialchars($type_list[0] ?? '');
                                    $line1_parts[] = '<span style="background-color: #aaf7a7; padding: 2px 8px; border-radius: 4px; font-weight: bold; color: #333;">' . $type_value . '</span>';
                                }
                                
                                if (!empty($pub['journal'])) {
                                    $journal_display = !empty($pub['journal_plain']) && $pub['journal'] !== $pub['journal_plain'] ? $pub['journal'] : htmlspecialchars($pub['journal']);
                                    $line1_parts[] = $journal_display;
                                }
                                
                                if (!empty($pub['volume'])) {
                                    $line1_parts[] = '<strong>Volume</strong> ' . htmlspecialchars($pub['volume']);
                                }
                                
                                if (!empty($pub['issue'])) {
                                    $line1_parts[] = '<strong>Issue</strong> ' . htmlspecialchars($pub['issue']);
                                }
                                
                                if (!empty($pub['year']) && $pub['year'] !== 'N/A') {
                                    $line1_parts[] = '<strong>Year</strong> ' . htmlspecialchars($pub['year']);
                                }
                                
                                if (!empty($pub['pages'])) {
                                    $line1_parts[] = '<strong>Pages</strong> ' . htmlspecialchars($pub['pages']);
                                }
                                
                                if (!empty($line1_parts)) {
                                    echo '<div class="card-meta">' . implode(' | ', $line1_parts) . '</div>';
                                }
                                ?>
                                <?php if(!empty($pub['doi'])): ?>
                                    <div class="card-meta"><strong>DOI:</strong> <a href="https://doi.org/<?= htmlspecialchars($pub['doi']) ?>" target="_blank"><?= htmlspecialchars($pub['doi']) ?></a></div>
                                <?php endif; ?>
                                <?php
                                if (!empty($pub['dept_list'])) {
                                    $dept_list = is_array($pub['dept_list']) ? $pub['dept_list'] : explode('; ', $pub['department']);
                                    $dept_list = array_filter(array_map('trim', $dept_list));
                                    if (count($dept_list) > 1) {
                                        echo '<div class="card-meta"><strong>Department:</strong> ' . htmlspecialchars(implode(' | ', $dept_list)) . '</div>';
                                    } elseif (!empty($pub['department'])) {
                                        echo '<div class="card-meta"><strong>Department:</strong> ' . htmlspecialchars($pub['department']) . '</div>';
                                    }
                                }
                                ?>
                            </div>
                            <?php if(!empty($pub['doi'])): ?>
                            <div class="content-badges">
                                <span class="metrics-badge-line" id="badge-line-<?= $index ?>">
                                    <div data-badge-type='donut' class='altmetric-embed' data-badge-popover='left' data-hide-no-mentions='true' data-doi='<?= htmlspecialchars($pub['doi']) ?>'></div>
                                    <span class="__dimensions_badge_embed__" data-doi="<?= htmlspecialchars($pub['doi']) ?>" data-hide-zero-citations="true" data-style="small_circle"></span>
                                    <span class="plumx-details-badge" data-badge="true" data-doi="<?= htmlspecialchars($pub['doi']) ?>"></span>
                                    <span class="crossref-badge" data-doi="<?= htmlspecialchars($pub['doi']) ?>">
                                        <span class="crossref-count">Loading...</span>
                                    </span>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php elseif($solr_loaded): ?>
            <div class="card">
                <p style="color: #666; padding: 20px;">
                    <?php if(!empty($search_info)): ?>
                        No publications found for <?= implode(', ', $search_info) ?>.<br>
                    <?php else: ?>
                        No publications found matching your criteria.<br>
                    <?php endif; ?>
                    <strong>Suggestions:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <li>Try removing some filters</li>
                        <li>Check the spelling of your search terms</li>
                        <li>Use more general keywords</li>
                    </ul>
                </p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- PAGINATION -->
    <?php if($solr_loaded && $total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination-nav">
            <a href="?<?= build_page_url($base_params, $current_page - 1) ?>" class="pagination-btn <?= $current_page <= 1 ? 'disabled' : '' ?>">Previous</a>
            
            <?php
            $maxPages = 7;
            $startPage = max(1, $current_page - floor($maxPages / 2));
            $endPage = min($total_pages, $startPage + $maxPages - 1);
            
            if ($endPage - $startPage < $maxPages - 1) {
                $startPage = max(1, $endPage - $maxPages + 1);
            }
            
            if ($startPage > 1) {
                echo '<a href="?'.build_page_url($base_params, 1).'" class="pagination-page">1</a>';
                if ($startPage > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                echo '<a href="?'.build_page_url($base_params, $i).'" class="pagination-page '.($i == $current_page ? 'active' : '').'">'.$i.'</a>';
            }
            
            if ($endPage < $total_pages) {
                if ($endPage < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?'.build_page_url($base_params, $total_pages).'" class="pagination-page">'.$total_pages.'</a>';
            }
            ?>
            
            <a href="?<?= build_page_url($base_params, $current_page + 1) ?>" class="pagination-btn <?= $current_page >= $total_pages ? 'disabled' : '' ?>">Next</a>
        </div>
        <div class="pagination-info">Page <?= $current_page ?> of <?= $total_pages ?></div>
    </div>
    <?php endif; ?>
</main>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const crossrefBadges = document.querySelectorAll('.crossref-badge');
    
    crossrefBadges.forEach(function(badge) {
        const doi = badge.getAttribute('data-doi');
        if (!doi) return;
        
        fetch('https://api.crossref.org/works/' + encodeURIComponent(doi))
            .then(response => response.json())
            .then(data => {
                const count = data.message['is-referenced-by-count'] || 0;
                
                if (!count || count === 0) {
                    badge.classList.add('crossref-hidden');
                    return;
                }
                
                badge.innerHTML = '<span class="crossref-count">' + count + '</span> <span style="font-size:9px;color:#666;">Crossref</span>';
            })
            .catch(error => {
                badge.classList.add('crossref-hidden');
            });
    });
});

// Export functionality
function handleExport(format) {
    if (!format) return;
    
    var formatDisplay = format.toUpperCase();
    var currentUrl = window.location.href;
    var url = new URL(currentUrl);
    
    // Build query string from current filters
    var queryString = '';
    var params = new URLSearchParams(url.search);
    
    // Add all existing parameters except page
    params.delete('page');
    
    var queryParts = [];
    params.forEach(function(value, key) {
        if (Array.isArray(value)) {
            value.forEach(function(v) {
                queryParts.push(key + '[]=' + encodeURIComponent(v));
            });
        } else {
            queryParts.push(key + '=' + encodeURIComponent(value));
        }
    });
    
    if (queryParts.length > 0) {
        queryString = '&' + queryParts.join('&');
    }
    
    // Show confirmation and redirect to export
    var exportUrl = 'export.php?action=export&format=' + format + queryString;
    window.location.href = exportUrl;
}
</script>

</body>
</html>
