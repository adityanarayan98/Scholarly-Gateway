# Scholarly Gateway

A DSpace-based CRIS (Current Research Information System) portal for searching and browsing research publications. This portal provides publication search and filtering capabilities using Apache Solr.

## Contributors

- Aditya Naryan Sahoo
- Shruti Rawal
- Dr. Kannan P

## Features

- Full-text search across publications
- Filter by year, author, department, type, language, country, source, and open access status
- Faceted search with modal popup for large filter lists
- Year range slider for temporal filtering
- Integration with Altmetric, Dimensions, PlumX, and Crossref for research metrics
- Responsive design
- Pagination with customizable results per page
- Export functionality (CSV, JSON, TXT)

## File Structure

```
.
├── helpers.php           # Shared helper functions (21 functions)
├── publications_api.php  # JSON API endpoint for AJAX requests
├── publications.php     # Full HTML page with search UI
├── export.php           # Export functionality (CSV, JSON, TXT)
├── docs/
│   ├── DEVELOPER_GUIDE.md
│   └── USER_GUIDE.md
└── LICENSE
```

## Requirements

- PHP 7.4 or higher
- Apache Solr server (configured for DSpace)
- Web server (Apache, Nginx, etc.)

## Installation

1. Clone this repository to your web server
2. Update configuration in `helpers.php`:
   - Set `$SOLR_URL` to your Solr server URL
   - Set `$COMMUNITY_ID` to your community ID
   - Update collection IDs for your installation
   - Update repository URL

3. Ensure your Solr server is running and indexed with DSpace data

## Configuration

Edit `helpers.php` to configure:

```php
$SOLR_URL = "http://your-solr-server:8983/solr/search/select";
$COMMUNITY_ID = "your-community-id";
```

## Usage

- Access `publications.php` for the full search interface
- Use `publications_api.php` for AJAX/API requests
- API parameters:
  - `q` - Search query
  - `author[]` - Filter by author
  - `year[]` - Filter by year
  - `type[]` - Filter by publication type
  - `department[]` - Filter by department
  - `page` - Page number
  - `per_page` - Results per page (25, 50, 100, 500)

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For issues or questions, please contact the development team.
