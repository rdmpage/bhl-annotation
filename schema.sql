CREATE TABLE "bhl_item" (
    ItemID INTEGER PRIMARY KEY
  , TitleID INTEGER
  , title TEXT
);

CREATE TABLE "bhl_page" (
    PageID INTEGER PRIMARY KEY
  , ItemID INTEGER
  , number TEXT
  , text TEXT
);

CREATE TABLE "bhl_title" (
    TitleID INTEGER PRIMARY KEY
  , title TEXT
  , issn TEXT
  , oclc INTEGER
  , doi TEXT
);

CREATE TABLE "bhl_tuple" (
    TitleID INTEGER
  , ItemID INTEGER NOT NULL
  , PageID INTEGER NOT NULL PRIMARY KEY
  , scan_order INTEGER NOT NULL
  , sequence INTEGER DEFAULT(0)
  , sequence_label TEXT
  , page_label TEXT
);

