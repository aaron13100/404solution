/* select from the old data. include every column except the id.  */
SELECT oldt.url, oldt.status, oldt.type, oldt.final_dest, oldt.code, oldt.disabled, oldt.timestamp
FROM wp_wbz404_redirects oldt
    left outer join wp_abj404_redirects newt
    on oldt.url = newt.url

/* only include rows where the URL does not already have a redirect in place (to avoid duplicates). */
where newt.url is null
