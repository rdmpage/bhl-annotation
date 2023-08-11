# bhl-annotation

Annotating pages in BHL (and elsewhere?)

## Locating names

### In BHL text

1. `php fetch_from_bhl.php` uses BHL API to fetch metadata about titles, items, and pages. 
2. The script `to-sql.php` attempts to parse item and page information into a “tuple” that combines information on volume, issue, and also tries to handle cases where item has more than one thing, such as multiple volumes or issues. Goal is to have unique tuples that can be easily queried. This code is a bit flaky and limited, and doesn’t handle cases such as series (where the same combination of volume and page number may reappear more than once).
3. The input for matching is a .tsv file listing id for a name (ideally a PID), the name string, and a micro citation. We attempt to parse the citation and match it to a one or more BHL pages. This depends on our ability to parse the citation, and to match it to a BHL title.
4. Given a .tsv file we run `php find_names_in_pages.php` which attempts these steps. If successful it emits SQL with details on the match. We store in the table `page` any matches between citation and BHL (i.e., if we think we have matched citation to a BHL page), and in the table `annotation` any hits where we have found the string on a BHL page.

### On page image

To do this we need coordinates of words on the page. We can get this from Internet Archive’s OCR files (e.g., DjVu, hOCR). We extract text, find name, convert to tokens, and get bounding box of those tokens. This can be done independently of BHL, but if content is in BHL we would like to be able to make the mapping between BHL and InternetArchive so that we can use bookmarklet to display names in BHL.

## Storing annotations

Need to think about storing these annotations. Perhaps as CSV and JSON files on Zenodo, one Zenodo record per BHL item (e.g., all annotations for a volume of a journal).

## Displaying annotations

### Standalone viewer

Exploring idea of a IIIF-like viewer for which we have a simple file with annotations found, say, using the methods here. This way we can have a viewer built separately, then simply add a file. Could host as static files. Could also edit the annotation file to add/remove things.

### BHL bookmarklet

Use a bookmarklet to map


## Text search

Text search seems flaky, sometimes seeing error = 0 will find a string whereas error = 1 won’t, even if string is in the text(!). Worse, we don’t get an error message about this :( Need to investigate a bit more.

## Get data to match from taxonomic database

### IPNI

```
SELECT id, fullnamewithoutfamilyandauthors, publication, collation
FROM names
WHERE fullnamewithoutfamilyandauthors != "" 
AND collation != ""
AND id="77175892-1";
```


## Add to taxonomic database


### IPNI

```
SELECT DISTINCT "UPDATE names SET bhl=" || annotation.bhlpageid || " WHERE id=""" || annotation.string_id || """;"
FROM bhl_tuple
INNER JOIN annotation ON bhl_tuple.PageID = annotation.bhlpageid
WHERE bhl_tuple.TitleID=77306;
```

## Prior art

### Global names

See [bhlnames](https://github.com/gnames/bhlnames). Data from this project is at [opendata.globalnames.org](http://opendata.globalnames.org).

### Annotations 

[Text Mining for Biodiversity](http://www.nactem.ac.uk/biodiversity/)

[COnserving Philippine bIOdiversity by UnderStanding big data (COPIOUS): Integration and analysis of heterogeneous information on Philippine biodiversity](http://www.nactem.ac.uk/copious/)

Construction of a biodiversity knowledge repository using a text mining-based framework https://ceur-ws.org/Vol-1743/paper1.pdf


Batista-Navarro, R., Zerva, C., Nguyen, N.T.H., Ananiadou, S. (2017). A Text Mining-Based Framework for Constructing an RDF-Compliant Biodiversity Knowledge Repository. In: Lossio-Ventura, J., Alatrista-Salas, H. (eds) Information Management and Big Data. SIMBig SIMBig 2015 2016. Communications in Computer and Information Science, vol 656. Springer, Cham. https://doi.org/10.1007/978-3-319-55209-5_3

Nguyen N, Gabud R, Ananiadou S (2019) COPIOUS: A gold standard corpus of named entities towards extracting species occurrence from biodiversity literature. Biodiversity Data Journal 7: e29626. https://doi.org/10.3897/BDJ.7.e29626




