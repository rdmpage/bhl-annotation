// Bookmarklet

// http://code.tutsplus.com/tutorials/create-bookmarklets-the-right-way--net-18154
// http://stackoverflow.com/questions/5281007/bookmarklets-which-creates-an-overlay-on-page

// Note that we need to have the Access-Control-Allow-Origin * header set for AJAX calls
// to work. Can do this in .htaccess
// https://stackoverflow.com/questions/10143093/origin-is-not-allowed-by-access-control-allow-origin

// Globals (yeah, I know...)
var observer = null;

// Map between BHL PageIDs and zero-offset array of page numbers [0,1,...]
var page_image_map = null;

// Identifier for this item
var guid = {
  namespace: null,
  identifier: null
};


rdmp_init();

//----------------------------------------------------------------------------------------
function rdmp_init() {
  // http://code.tutsplus.com/tutorials/create-bookmarklets-the-right-way--net-18154
  // Test for presence of jQuery, if not, add it
  if (!($ = window.jQuery)) { // typeof jQuery=='undefined' works too
    // Create a script tag to load the bookmarklet
    script = document.createElement('script');
    script.src = '//ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js';
    script.onload = releasetheKraken;
    document.body.appendChild(script);
  }
  else {
    releasetheKraken();
  }
}

//----------------------------------------------------------------------------------------
// Close the panel
function rdmp_close(id) {
  $('#' + id).remove();
}

//----------------------------------------------------------------------------------------
// Draw annotation on page being displayed. Because BHL viewer may create and destroy 
// pages as user scrolls we can't always assume annotation exists even if we've previously
// created it, so need to check each time
function show_annotations(guid, PageID) {
  // Draw annotation on page

  // id of div enclosing the page image
  var page_index = page_image_map[PageID];
  var id = 'pagediv' + page_index;
  var page_image = document.getElementById(id);

  // load annotations, these could be at page level but for now they are at item level
  $.ajax({
    type: "GET",
    url: "//bionames.org/bhlx/" + guid.identifier + '.json',
    success: function(data) {
      console.log(JSON.stringify(data,null,2));


      // Do we have annotations for this page?
      if (data[page_index]) {

        // debugging
       //document.getElementById('annotation_info').innerHTML = JSON.stringify(data, null, 2);

		var html = '<h3>Annotations</h3>';
		html += '<ul>';
		var n = data[page_index].length;
         for (var i=0; i < n; i++) {
         	html += '<li>' + data[page_index][i].body + '</li>';
         }
         html += '</ul>';
        document.getElementById('annotation_info').innerHTML = html;

        // paint annotations
        for (var i=0; i < n; i++) {

          // Need to scale to current page view, annotation coordinates are for
          // images in Internet Archive downloads
          var scale = page_image.clientWidth / data[page_index][i].width;

          // Parse the fragment identifier
          var m = data[page_index][i].fragment.match(/xywh=(\d+),(\d+),(\d+),(\d+)/);
          if (m) {
            var annotation_id = "anno-" + page_index + '-' + i;

            // only draw if not already displayed
            if (document.getElementById(annotation_id)) {
              // it already exists, don't recreate
            }
            else {
              // OK, we need to make it
              var e = document.createElement('div');
              e.id = annotation_id;

              //style
              e.style.position = 'absolute';
              e.style.left = (scale * m[1]) + 'px';
              e.style.top = (scale * m[2]) + 'px';
              e.style.width = (scale * m[3]) + 'px';
              e.style.height = (scale * m[4]) + 'px';
              e.style.background = 'red';
              e.style.opacity = '0.5';
              e.style.border = '1px solid black';
              e.style.padding = '1px';

              // append it to the page:
              page_image.appendChild(e);
            }
          }
        }
      }
    }
  });

}

//--------------------------------------------------------------------------------------------------
// This is where we create the panel
function releasetheKraken() {

  var e = null;
  if (!$('#pidannotate').length) {

    // create the element:
    e = $('<div class="pidannotate" id="pidannotate"></div>');

    // append it to the body:
    $('body').append(e);

    var styles = `
    	.pidannotate {
			position:    		fixed;
			top:         		0px;
			right:       		0px;
			width:       		300px;
			/*height:      		100vh;*/
			height:              auto;
			padding:     		20px;
			background-color: 	#FFFFCC;
			color:       		#666666;
			text-align:  		left;
			font-size:   		12px;
			font-weight: 		normal;
			font-family: 		Helvetica, Arial, sans-serif;
			box-shadow:  		-5px 5px 5px 0px rgba(50, 50, 50, 0.3);
			border-radius:		4px;
			z-index:     		200000;
			overflow-y:			auto;
    	}
    	
    	.pidannotate h1 {
    		font-size:14px;
    		line-height:18px;
    		font-weight:bold;
    		margin: 4px;
    		font-family: Helvetica, Arial, sans-serif;
    	}
    	
    	.pidannotate h2 {
    		font-size:12px;
    		line-height:14px;
    		font-weight:bold;
    		margin: 4px;
    		font-family: Helvetica, Arial, sans-serif;
    	}
    	    	
    	.pidannotate a {
    		text-decoration:none;
			color:rgb(28,27,168);
    	}   
    	
    	.pidannotate a:hover {
			text-decoration:underline;
		}
				
    `;

    var styleSheet = document.createElement("style")
    styleSheet.type = "text/css"
    styleSheet.innerText = styles
    document.head.appendChild(styleSheet)

    $('#pidannotate').data("top", $('#pidannotate').offset().top);
  }
  else {
    e = $('#pidannotate');
  }

  // Close button for panel
  var html = '<span style="float:right;" onclick="rdmp_close(\'pidannotate\')">Close [x]</span>';

  // Display the title of the BHL item
  html += '<h1>' + window.document.title + '</h1>';
  e.html(html);

  // Get identifier(s) from page elements or URL
  // http://stackoverflow.com/questions/7524585/how-do-i-get-the-information-from-a-meta-tag-with-javascript
  if (!guid.namespace) {
    // Get identifier from meta tags
    var metas = document.getElementsByTagName('meta');

    for (i = 0; i < metas.length; i++) {
      if (metas[i].getAttribute("name") == "DC.identifier.URI") {
        var m = metas[i].getAttribute("content").match(/https?:\/\/(?:www.)?biodiversitylibrary.org\/item\/(\d+)/);
        if (m) {
          guid.namespace = 'bhl';
          guid.identifier = m[1];
          guid.uri = 'https://www.biodiversitylibrary.org/item/' + guid.identifier;
        }
      }
    }
  }

  // If we have an identifier we can do stuff
  if (guid.namespace) {

    switch (guid.namespace) {

      case 'bhl':
        // Get mapping between PageID and order in page list (assumes querySelectorAll returns
        // elments in same order as they apear in the DOM). This means we can work out which page
        // in the list [0, 1, ...] a given PageID corresponds too.
        if (!page_image_map) {
          page_image_map = {};
          var option = document.querySelectorAll("select[id=lstPages] > option");
          for (i = 0; i < option.length; i++) {
            page_image_map[option[i].getAttribute('value')] = i;
          }
        }
        //console.log(JSON.stringify(page_image_map));

        var html = '<div id="bhl_page"></div><div id="annotation_info"></div>';
        e.html(e.html() + '<br />' + html);

        var currentpageURL = document.querySelector('[id=currentpageURL]');
        var PageID = currentpageURL.getAttribute('href').replace('https://www.biodiversitylibrary.org/page/', '');

        // Debugging
        document.getElementById('bhl_page').innerHTML = 'BHL page: ' + PageID;

        // Show annotations (if any) for this page
        show_annotations(guid, PageID);

        // BHL pages can change as user browses content, so we use a MutationObserver
        // to track current PageID, so that we could then display annotations relevant
        // to the page being displayed. This means anything we do is triggered by how BHL
        // updates the link to the page being displayed. In practice this means that a 
        // page can appear in the BHL viewer but not show any annotations until the BHL 
        // viewer changes the page link.
        // We listen to changes in attribute on element [id=currentpageURL]

        // https://stackoverflow.com/questions/41424989/javascript-listen-for-attribute-change
        observer = new MutationObserver(function(mutations) {
          mutations.forEach(function(mutation) {
            if (mutation.type == "attributes") {

              // Get the BHL page id
              var currentpageURL = document.querySelector('[id=currentpageURL]');
              var PageID = currentpageURL.getAttribute('href').replace('https://www.biodiversitylibrary.org/page/', '');

              // Debugging
              document.getElementById('bhl_page').innerHTML = 'BHL page: ' + PageID;

              // Show annotations (if any) for this page
              document.getElementById('annotation_info').innerHTML = "";
              show_annotations(guid, PageID);
            }
          });
        });

        observer.observe(currentpageURL, {
          attributes: true //configure it to listen to attribute changes
        });
        break;

      default:
        break;
    }

  }

}

//----------------------------------------------------------------------------------------
/* Can't use jquery at this point because it might not have been loaded yet */
// https://stackoverflow.com/a/17494943/9684

var startProductBarPos = -1;

window.onscroll = function() {
  var bar = document.getElementById('pidannotate');
  if (startProductBarPos < 0) startProductBarPos = findPosY(bar);

  if (pageYOffset > startProductBarPos) {
    bar.style.position = 'fixed';
    bar.style.top = 0;
  }
  else {
    bar.style.position = 'fixed';
  }

};

function findPosY(obj) {
  var curtop = 0;
  if (typeof(obj.offsetParent) != 'undefined' && obj.offsetParent) {
    while (obj.offsetParent) {
      curtop += obj.offsetTop;
      obj = obj.offsetParent;
    }
    curtop += obj.offsetTop;
  }
  else if (obj.y)
    curtop += obj.y;
  return curtop;
}