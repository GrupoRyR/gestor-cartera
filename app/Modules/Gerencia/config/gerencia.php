<?php

// Gerencia settings, merged by GerenciaServiceProvider under the "gerencia" key:
// read them with config('gerencia.<setting>'). Only this file calls env().
return [

    // Name of the group view, shown in place of a company name.
    'group_name' => 'Grupo RYR',

    // Months of portfolio history behind the trend charts.
    'trend_months' => 12,

    // Commitments read per page, and the most pages read for the compliance
    // figures; past the cap the report is flagged as partial.
    'compliance_page_size' => 200,
    'compliance_max_pages' => 50,

    // Newest activity-log entries read to count the Cobro emails of a period;
    // when the log is longer, the report is flagged as partial.
    'activity_read_limit' => 5000,

];
