
insert into {NEW_TABLE} (url, status, type, final_dest, code, disabled, timestamp)

/* select from the old data. include every column except the id.  */
SELECT oldt.url, oldt.status, oldt.type, oldt.final_dest, oldt.code, oldt.disabled, oldt.timestamp
FROM {OLD_TABLE} oldt
    left outer join {NEW_TABLE} newt
    on cast(oldt.url as binary) = cast(newt.url as binary)
    /* cast both as binary due to an "Illegal mix of collations" error. see github issue #12. */

/* only include rows where the URL does not already have a redirect in place (to avoid duplicates). */
where newt.url is null
