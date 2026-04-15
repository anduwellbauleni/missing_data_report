# missing_data_report
Missing Data Report Module for REDCap
**Overview**
The Missing Data Report Module provides a quick and efficient way to identify incomplete data within a REDCap project. It generates a table summarizing missing values at the form level, helping users monitor data quality and completeness. This is meant to help data management for regular REDCap users who don't have to run queries and also give them the ability to filter by forms/CRFs or event or ID to make visualization simple

**Features**
Displays a report with:
Record ID
REDCap Event
Arm
Repeat Instrument Name
Repeat Instance
Form Name
Missing (count of fields with missing data)
Fields (comma-separated list of missing variables)
**Filterable by:**
Form name
Event
Record ID
Exportable as a CSV file

**Purpose**

This module provides a centralized view of missing data, allowing users to quickly identify gaps and prioritize data cleaning efforts.

**Output**

The generated table highlights where data is incomplete and which specific variables are missing, making it easier to take corrective action.
![Sample Missing Data Report](missing_data_report/samplepic.png)
