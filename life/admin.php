<?php

session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_OFF);


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function response($data, $status = 200)
{
    http_response_code($status);

    while (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| ERROR HANDLING
|--------------------------------------------------------------------------
*/

set_error_handler(function ($severity, $message, $file, $line) {

    error_log(
        "PHP Error [$severity]: $message in $file on line $line"
    );

    if (!(error_reporting() & $severity)) {
        return false;
    }

    response([
        "success" => false,
        "message" => "Internal server error."
    ], 500);
});


set_exception_handler(function ($exception) {

    error_log(
        "Uncaught Exception: " .
        $exception->getMessage() .
        " in " .
        $exception->getFile() .
        " on line " .
        $exception->getLine()
    );

    response([
        "success" => false,
        "message" => $exception->getMessage()
    ], 500);
});


register_shutdown_function(function () {

    $error = error_get_last();

    if ($error !== null) {

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR
        ];

        if (in_array($error['type'], $fatalTypes, true)) {

            error_log(
                "Fatal Error: " .
                $error['message'] .
                " in " .
                $error['file'] .
                " on line " .
                $error['line']
            );

            if (!headers_sent()) {
                http_response_code(500);
                header("Content-Type: application/json; charset=UTF-8");
            }

            while (ob_get_level()) {
                ob_end_clean();
            }

            echo json_encode([
                "success" => false,
                "message" => "Internal server error."
            ]);
        }
    }
});


/*
|--------------------------------------------------------------------------
| ACTION
|--------------------------------------------------------------------------
*/

$action = trim($_GET['action'] ?? '');


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

if ($action === 'logout') {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    response([
        "success" => true,
        "message" => "Logged out successfully."
    ]);
}


/*
|--------------------------------------------------------------------------
| SESSION CHECK
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {

    response([
        "success" => false,
        "message" => "Not logged in."
    ], 401);
}


if (
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {

    response([
        "success" => false,
        "message" => "Admin access required."
    ], 403);
}


/*
|--------------------------------------------------------------------------
| CHECK SESSION
|--------------------------------------------------------------------------
*/

if ($action === 'check_session') {

    response([
        "success" => true,
        "logged_in" => true,
        "role" => $_SESSION['role'] ?? '',
        "staff_name" => $_SESSION['staff_name'] ?? '',
        "sales_man_code" => $_SESSION['sales_man_code'] ?? '',
        "outlet" => $_SESSION['outlet'] ?? ''
    ]);
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$host = "serverless-eastus.sysp0000.db3.skysql.com";
$user = "dbpbf12883888";
$password = "nyy6hj6v0#s61c2fTw%i";
$database = "incentive";
$port = 4003;


/*
|--------------------------------------------------------------------------
| CONNECT TO SKYSQL
|--------------------------------------------------------------------------
*/

$mysqli = mysqli_init();

if (!$mysqli) {

    response([
        "success" => false,
        "message" => "Unable to initialise database."
    ], 500);
}


mysqli_ssl_set(
    $mysqli,
    null,
    null,
    null,
    null,
    null
);


if (!mysqli_real_connect(
    $mysqli,
    $host,
    $user,
    $password,
    $database,
    $port,
    null,
    MYSQLI_CLIENT_SSL
)) {

    error_log(
        "Database connection failed: " .
        mysqli_connect_error()
    );

    response([
        "success" => false,
        "message" => "Database connection failed."
    ], 500);
}


mysqli_set_charset(
    $mysqli,
    "utf8mb4"
);


/*
|--------------------------------------------------------------------------
| JSON INPUT
|--------------------------------------------------------------------------
*/

function getJsonInput()
{
    $raw = file_get_contents("php://input");

    if ($raw !== false && trim($raw) !== '') {

        $data = json_decode($raw, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($data)
        ) {
            return $data;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| CLEAN VALUE
|--------------------------------------------------------------------------
*/

function cleanValue($value)
{
    if ($value === null) {
        return '';
    }

    if (is_array($value)) {
        return '';
    }

    return trim((string)$value);
}


/*
|--------------------------------------------------------------------------
| NORMALISE PROMOTION TYPE
|--------------------------------------------------------------------------
*/

function normalizePromotionType($type)
{
    $type = strtolower(
        trim((string)$type)
    );

    $type = str_replace(
        '-',
        '_',
        $type
    );


    if (
        $type === 'bundle' ||
        $type === 'outlet bundle' ||
        $type === 'outlet_bundle' ||
        $type === 'outletbundle'
    ) {
        return 'outlet_bundle';
    }


    if (
        $type === 'individual' ||
        $type === 'individual incentive' ||
        $type === 'individual_incentive'
    ) {
        return 'individual';
    }


    return $type;
}


/*
|--------------------------------------------------------------------------
| PROMOTION STATUS
|--------------------------------------------------------------------------
*/

function getCalculatedPromotionStatus(
    $startDate,
    $endDate
) {

    $today = date('Y-m-d');

    $startDate = cleanValue($startDate);
    $endDate = cleanValue($endDate);


    if (
        $startDate !== '' &&
        $today < $startDate
    ) {
        return 'Upcoming';
    }


    if (
        $endDate !== '' &&
        $today > $endDate
    ) {
        return 'Expired';
    }


    return 'Active';
}


/*
|--------------------------------------------------------------------------
| SYNC PROMOTION STATUS
|--------------------------------------------------------------------------
*/

function syncPromotionStatuses($mysqli)
{
    $sql = "
        SELECT
            promo_id,
            start_date,
            end_date
        FROM Incentives_information
    ";


    $result = $mysqli->query($sql);


    if (!$result) {
        return;
    }


    $stmt = $mysqli->prepare("
        UPDATE Incentives_information
        SET status = ?
        WHERE promo_id = ?
    ");


    if (!$stmt) {
        return;
    }


    while ($row = $result->fetch_assoc()) {

        $status =
            getCalculatedPromotionStatus(
                $row['start_date'] ?? '',
                $row['end_date'] ?? ''
            );

        $promoId =
            (int)$row['promo_id'];


        $stmt->bind_param(
            "si",
            $status,
            $promoId
        );

        $stmt->execute();
    }


    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| FETCH SUBMISSIONS
|--------------------------------------------------------------------------
*/

function fetchSubmissions($mysqli)
{
    $sql = "
        SELECT

            si.incentive_id,

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            si.promo_id,

            ii.promo,
            ii.Rate,
            ii.type,

            ii.start_date,
            ii.end_date,
            ii.status AS promo_status,

            si.quantity,
            si.status,

            si.submission_date,

            si.proof,
            si.rejection_reason,

            bi.item_1,
            bi.item_2,
            bi.item_3,
            bi.item_4,
            bi.item_5

        FROM staff_incentives si

        LEFT JOIN staff_details sd
            ON si.sales_man_code = sd.sales_man_code

        LEFT JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        LEFT JOIN Bundle_information bi
            ON ii.promo_id = bi.promo_id

        ORDER BY
            si.submission_date DESC,
            si.incentive_id DESC
    ";


    $result = $mysqli->query($sql);


    if (!$result) {

        throw new Exception(
            "Unable to fetch submissions: " .
            $mysqli->error
        );
    }


    $submissions = [];


    while ($row = $result->fetch_assoc()) {

        $quantity =
            (int)($row['quantity'] ?? 0);

        $rate =
            (float)($row['Rate'] ?? 0);


        $amount =
            $quantity * $rate;


        $row['quantity'] =
            $quantity;

        $row['Rate'] =
            $rate;

        $row['amount'] =
            $amount;

        $row['incentive_amount'] =
            $amount;


        $submissions[] = $row;
    }


    return $submissions;
}


/*
|--------------------------------------------------------------------------
| FETCH PROMOTIONS
|--------------------------------------------------------------------------
*/

function fetchPromotions($mysqli)
{
    $sql = "
        SELECT

            ii.promo_id,
            ii.promo,
            ii.Rate,
            ii.type,

            ii.start_date,
            ii.end_date,
            ii.status,

            bi.item_1,
            bi.item_2,
            bi.item_3,
            bi.item_4,
            bi.item_5

        FROM Incentives_information ii

        LEFT JOIN Bundle_information bi
            ON ii.promo_id = bi.promo_id

        ORDER BY
            ii.promo_id DESC
    ";


    $result = $mysqli->query($sql);


    if (!$result) {

        throw new Exception(
            "Unable to fetch promotions: " .
            $mysqli->error
        );
    }


    $promotions = [];


    while ($row = $result->fetch_assoc()) {
        $promotions[] = $row;
    }


    return $promotions;
}


/*
|--------------------------------------------------------------------------
| FETCH USERS
|--------------------------------------------------------------------------
*/

function fetchUsers($mysqli)
{
    $sql = "
        SELECT
            sales_man_code,
            staff_name,
            outlet,
            role

        FROM staff_details

        ORDER BY
            staff_name ASC
    ";


    $result = $mysqli->query($sql);


    if (!$result) {

        throw new Exception(
            "Unable to fetch users: " .
            $mysqli->error
        );
    }


    $users = [];


    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }


    return $users;
}


/*
|--------------------------------------------------------------------------
| BUILD DASHBOARD
|--------------------------------------------------------------------------
|
| $submissions is optional so existing calls such as:
|
| buildDashboard($mysqli, $submissions)
|
| will not cause "too many arguments".
|
*/

function buildDashboard(
    $mysqli,
    $submissions = null
) {

    $stats = [

        'totalSubmissions' => 0,

        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,

        'approvedIndividual' => 0,
        'outletBundlePool' => 0,

        'totalIncentive' => 0,
        'bundleTotal' => 0,

        'staffCount' => 0
    ];


    /*
    |--------------------------------------------------------------------------
    | SUBMISSION COUNTS
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT

            COUNT(*) AS totalSubmissions,

            SUM(
                CASE
                    WHEN status = 'pending'
                    THEN 1
                    ELSE 0
                END
            ) AS pending,

            SUM(
                CASE
                    WHEN status = 'approved'
                    THEN 1
                    ELSE 0
                END
            ) AS approved,

            SUM(
                CASE
                    WHEN status = 'rejected'
                    THEN 1
                    ELSE 0
                END
            ) AS rejected

        FROM staff_incentives
    ";


    $result =
        $mysqli->query($sql);


    if (
        $result &&
        ($row = $result->fetch_assoc())
    ) {

        $stats['totalSubmissions'] =
            (int)($row['totalSubmissions'] ?? 0);

        $stats['pending'] =
            (int)($row['pending'] ?? 0);

        $stats['approved'] =
            (int)($row['approved'] ?? 0);

        $stats['rejected'] =
            (int)($row['rejected'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | APPROVED INDIVIDUAL INCENTIVE
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT

            COALESCE(
                SUM(
                    si.quantity * ii.Rate
                ),
                0
            ) AS total

        FROM staff_incentives si

        INNER JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        WHERE
            si.status = 'approved'

            AND ii.type = 'individual'
    ";


    $result =
        $mysqli->query($sql);


    if (
        $result &&
        ($row = $result->fetch_assoc())
    ) {

        $stats['approvedIndividual'] =
            (float)($row['total'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | APPROVED OUTLET BUNDLE INCENTIVE
    |--------------------------------------------------------------------------
    |
    | DO NOT use Bundle_information here.
    |
    | Bundle_information contains only:
    | item_1 - item_5
    |
    | The money comes from:
    |
    | quantity × Rate
    |
    */

    $sql = "
        SELECT

            COALESCE(
                SUM(
                    si.quantity * ii.Rate
                ),
                0
            ) AS total

        FROM staff_incentives si

        INNER JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        WHERE
            si.status = 'approved'

            AND ii.type = 'outlet_bundle'
    ";


    $result =
        $mysqli->query($sql);


    if (
        $result &&
        ($row = $result->fetch_assoc())
    ) {

        $stats['outletBundlePool'] =
            (float)($row['total'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | FRONTEND ALIASES
    |--------------------------------------------------------------------------
    */

    $stats['totalIncentive'] =
        $stats['approvedIndividual'];

    $stats['bundleTotal'] =
        $stats['outletBundlePool'];


    /*
    |--------------------------------------------------------------------------
    | STAFF COUNT
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT COUNT(*) AS total
        FROM staff_details
        WHERE role = 'staff'
    ";


    $result =
        $mysqli->query($sql);


    if (
        $result &&
        ($row = $result->fetch_assoc())
    ) {

        $stats['staffCount'] =
            (int)($row['total'] ?? 0);
    }


    return $stats;
}


/*
|--------------------------------------------------------------------------
| MONTHLY SUMMARY
|--------------------------------------------------------------------------
*/

function buildMonthlySummary(
    $mysqli,
    $month = ''
) {

    $month =
        cleanValue($month);


    $sql = "
        SELECT

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            DATE_FORMAT(
                si.submission_date,
                '%Y-%m'
            ) AS month,

            ii.type,

            COALESCE(
                SUM(
                    si.quantity * ii.Rate
                ),
                0
            ) AS total

        FROM staff_incentives si

        INNER JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        LEFT JOIN staff_details sd
            ON si.sales_man_code = sd.sales_man_code

        WHERE
            si.status = 'approved'
    ";


    if ($month !== '') {

        $sql .= "
            AND DATE_FORMAT(
                si.submission_date,
                '%Y-%m'
            ) = ?
        ";
    }


    $sql .= "

        GROUP BY

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            DATE_FORMAT(
                si.submission_date,
                '%Y-%m'
            ),

            ii.type

        ORDER BY

            month DESC,

            sd.staff_name ASC
    ";


    if ($month !== '') {

        $stmt =
            $mysqli->prepare($sql);


        if (!$stmt) {

            throw new Exception(
                "Monthly summary error: " .
                $mysqli->error
            );
        }


        $stmt->bind_param(
            "s",
            $month
        );


        $stmt->execute();


        $result =
            $stmt->get_result();

    } else {

        $result =
            $mysqli->query($sql);
    }


    if (!$result) {

        throw new Exception(
            "Unable to fetch monthly summary: " .
            $mysqli->error
        );
    }


    $summary = [];


    while ($row = $result->fetch_assoc()) {

        $row['total'] =
            (float)($row['total'] ?? 0);

        $summary[] = $row;
    }


    return $summary;
}


/*
|--------------------------------------------------------------------------
| TOP 3
|--------------------------------------------------------------------------
*/

function buildTop3(
    $mysqli,
    $month = ''
) {

    $month =
        cleanValue($month);


    /*
    |--------------------------------------------------------------------------
    | INDIVIDUAL STAFF TOTALS
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            COALESCE(
                SUM(
                    si.quantity * ii.Rate
                ),
                0
            ) AS individual_total

        FROM staff_incentives si

        INNER JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        LEFT JOIN staff_details sd
            ON si.sales_man_code = sd.sales_man_code

        WHERE
            si.status = 'approved'

            AND ii.type = 'individual'
    ";


    if ($month !== '') {

        $sql .= "
            AND DATE_FORMAT(
                si.submission_date,
                '%Y-%m'
            ) = ?
        ";
    }


    $sql .= "

        GROUP BY

            si.sales_man_code,

            sd.staff_name,
            sd.outlet

        ORDER BY
            individual_total DESC
    ";


    if ($month !== '') {

        $stmt =
            $mysqli->prepare($sql);


        if (!$stmt) {

            throw new Exception(
                "Top 3 query error: " .
                $mysqli->error
            );
        }


        $stmt->bind_param(
            "s",
            $month
        );


        $stmt->execute();


        $result =
            $stmt->get_result();

    } else {

        $result =
            $mysqli->query($sql);
    }


    if (!$result) {

        throw new Exception(
            "Unable to calculate Top 3: " .
            $mysqli->error
        );
    }


    $staff = [];


    while ($row = $result->fetch_assoc()) {

        $row['individual_total'] =
            (float)(
                $row['individual_total'] ?? 0
            );

        $staff[] = $row;
    }


    /*
    |--------------------------------------------------------------------------
    | BUNDLE POOL
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT

            COALESCE(
                SUM(
                    si.quantity * ii.Rate
                ),
                0
            ) AS bundle_pool

        FROM staff_incentives si

        INNER JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        WHERE
            si.status = 'approved'

            AND ii.type = 'outlet_bundle'
    ";


    if ($month !== '') {

        $sql .= "
            AND DATE_FORMAT(
                si.submission_date,
                '%Y-%m'
            ) = ?
        ";
    }


    if ($month !== '') {

        $stmt =
            $mysqli->prepare($sql);


        if (!$stmt) {

            throw new Exception(
                "Bundle pool query error: " .
                $mysqli->error
            );
        }


        $stmt->bind_param(
            "s",
            $month
        );


        $stmt->execute();


        $result =
            $stmt->get_result();

    } else {

        $result =
            $mysqli->query($sql);
    }


    if (!$result) {

        throw new Exception(
            "Unable to calculate bundle pool: " .
            $mysqli->error
        );
    }


    $bundlePool = 0;


    if ($row = $result->fetch_assoc()) {

        $bundlePool =
            (float)(
                $row['bundle_pool'] ?? 0
            );
    }


    /*
    |--------------------------------------------------------------------------
    | TOP 3 PERCENTAGES
    |--------------------------------------------------------------------------
    */

    $percentages = [

        1 => 0.50,

        2 => 0.35,

        3 => 0.15
    ];


    $top3 = [];


    for ($i = 0; $i < 3; $i++) {

        $position =
            $i + 1;


        if (isset($staff[$i])) {

            $individual =
                (float)(
                    $staff[$i]['individual_total']
                );


            $bundleShare =
                $bundlePool *
                $percentages[$position];


            $totalPayout =
                $individual +
                $bundleShare;


            $top3[] = [

                'position' =>
                    $position,

                'sales_man_code' =>
                    $staff[$i]['sales_man_code'] ?? '',

                'staff_name' =>
                    $staff[$i]['staff_name'] ?? '',

                'outlet' =>
                    $staff[$i]['outlet'] ?? '',

                'individual_total' =>
                    $individual,

                'bundle_share' =>
                    $bundleShare,

                'total_payout' =>
                    $totalPayout,

                'percentage' =>
                    $percentages[$position] * 100
            ];

        } else {

            $top3[] = [

                'position' =>
                    $position,

                'sales_man_code' => '',

                'staff_name' => '',

                'outlet' => '',

                'individual_total' => 0,

                'bundle_share' => 0,

                'total_payout' => 0,

                'percentage' =>
                    $percentages[$position] * 100
            ];
        }
    }


    return [

        'bundlePool' =>
            $bundlePool,

        'top3' =>
            $top3
    ];
}


/*
|--------------------------------------------------------------------------
| STAFF PROMOTION BREAKDOWN
|--------------------------------------------------------------------------
*/

function getStaffPromotionBreakdown(
    $mysqli,
    $sales_man_code
) {

    $sales_man_code =
        cleanValue($sales_man_code);


    $sql = "
        SELECT

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            si.promo_id,

            ii.promo,
            ii.type,
            ii.Rate,

            SUM(
                si.quantity
            ) AS quantity,

            COALESCE(
                SUM(
                    si.quantity * ii.Rate
                ),
                0
            ) AS total

        FROM staff_incentives si

        INNER JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        LEFT JOIN staff_details sd
            ON si.sales_man_code = sd.sales_man_code

        WHERE
            si.sales_man_code = ?

            AND si.status = 'approved'

        GROUP BY

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            si.promo_id,

            ii.promo,
            ii.type,
            ii.Rate

        ORDER BY
            total DESC
    ";


    $stmt =
        $mysqli->prepare($sql);


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare staff breakdown."
        );
    }


    $stmt->bind_param(
        "s",
        $sales_man_code
    );


    $stmt->execute();


    $result =
        $stmt->get_result();


    $data = [];


    while ($row = $result->fetch_assoc()) {

        $row['quantity'] =
            (int)($row['quantity'] ?? 0);

        $row['Rate'] =
            (float)($row['Rate'] ?? 0);

        $row['total'] =
            (float)($row['total'] ?? 0);

        $data[] = $row;
    }


    $stmt->close();


    return $data;
}


/*
|--------------------------------------------------------------------------
| GET SINGLE SUBMISSION
|--------------------------------------------------------------------------
*/

function getSubmission(
    $mysqli,
    $id
) {

    $id =
        (int)$id;


    $sql = "
        SELECT

            si.incentive_id,

            si.sales_man_code,

            sd.staff_name,
            sd.outlet,

            si.promo_id,

            ii.promo,
            ii.Rate,
            ii.type,

            ii.start_date,
            ii.end_date,
            ii.status AS promo_status,

            si.quantity,
            si.status,

            si.submission_date,

            si.proof,
            si.rejection_reason,

            bi.item_1,
            bi.item_2,
            bi.item_3,
            bi.item_4,
            bi.item_5

        FROM staff_incentives si

        LEFT JOIN staff_details sd
            ON si.sales_man_code = sd.sales_man_code

        LEFT JOIN Incentives_information ii
            ON si.promo_id = ii.promo_id

        LEFT JOIN Bundle_information bi
            ON ii.promo_id = bi.promo_id

        WHERE
            si.incentive_id = ?

        LIMIT 1
    ";


    $stmt =
        $mysqli->prepare($sql);


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare submission query."
        );
    }


    $stmt->bind_param(
        "i",
        $id
    );


    $stmt->execute();


    $result =
        $stmt->get_result();


    $row =
        $result->fetch_assoc();


    $stmt->close();


    if (!$row) {
        return null;
    }


    $quantity =
        (int)($row['quantity'] ?? 0);

    $rate =
        (float)($row['Rate'] ?? 0);


    $row['quantity'] =
        $quantity;

    $row['Rate'] =
        $rate;

    $row['amount'] =
        $quantity * $rate;

    $row['incentive_amount'] =
        $quantity * $rate;


    return $row;
}


/*
|--------------------------------------------------------------------------
| APPROVE SUBMISSION
|--------------------------------------------------------------------------
*/

function approveSubmission(
    $mysqli,
    $id
) {

    $id =
        (int)$id;


    if ($id <= 0) {

        throw new Exception(
            "Invalid submission ID."
        );
    }


    $stmt =
        $mysqli->prepare("
            UPDATE staff_incentives

            SET
                status = 'approved',
                rejection_reason = NULL

            WHERE incentive_id = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare approval query."
        );
    }


    $stmt->bind_param(
        "i",
        $id
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to approve submission: " .
            $stmt->error
        );
    }


    $stmt->close();


    return [

        "success" => true,

        "message" =>
            "Submission approved successfully."
    ];
}


/*
|--------------------------------------------------------------------------
| REJECT SUBMISSION
|--------------------------------------------------------------------------
*/

function rejectSubmission(
    $mysqli,
    $id,
    $reason
) {

    $id =
        (int)$id;

    $reason =
        cleanValue($reason);


    if ($id <= 0) {

        throw new Exception(
            "Invalid submission ID."
        );
    }


    if ($reason === '') {

        throw new Exception(
            "Rejection reason is required."
        );
    }


    $stmt =
        $mysqli->prepare("
            UPDATE staff_incentives

            SET
                status = 'rejected',
                rejection_reason = ?

            WHERE incentive_id = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare rejection query."
        );
    }


    $stmt->bind_param(
        "si",
        $reason,
        $id
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to reject submission: " .
            $stmt->error
        );
    }


    $stmt->close();


    return [

        "success" => true,

        "message" =>
            "Submission rejected successfully."
    ];
}


/*
|--------------------------------------------------------------------------
| EDIT SUBMISSION
|--------------------------------------------------------------------------
*/

function editSubmission(
    $mysqli,
    $data
) {

    $id =
        (int)(
            $data['incentive_id'] ??
            $data['id'] ??
            0
        );


    $promoId =
        (int)(
            $data['promo_id'] ??
            0
        );


    $quantity =
        (int)(
            $data['quantity'] ??
            1
        );


    if ($id <= 0) {

        throw new Exception(
            "Invalid submission ID."
        );
    }


    if ($promoId <= 0) {

        throw new Exception(
            "Invalid promotion."
        );
    }


    if ($quantity <= 0) {

        throw new Exception(
            "Quantity must be greater than 0."
        );
    }


    $stmt =
        $mysqli->prepare("
            UPDATE staff_incentives

            SET
                promo_id = ?,
                quantity = ?

            WHERE
                incentive_id = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare submission update."
        );
    }


    $stmt->bind_param(
        "iii",
        $promoId,
        $quantity,
        $id
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to edit submission: " .
            $stmt->error
        );
    }


    $stmt->close();


    return [

        "success" => true,

        "message" =>
            "Submission updated successfully."
    ];
}


/*
|--------------------------------------------------------------------------
| ADD PROMOTION
|--------------------------------------------------------------------------
*/

function addPromotion(
    $mysqli,
    $data
) {

    $promo =
        cleanValue(
            $data['promo'] ??
            $data['promotion'] ??
            ''
        );


    $rate =
        (float)(
            $data['Rate'] ??
            $data['rate'] ??
            0
        );


    $type =
        normalizePromotionType(
            $data['type'] ?? ''
        );


    $startDate =
        cleanValue(
            $data['start_date'] ?? ''
        );


    $endDate =
        cleanValue(
            $data['end_date'] ?? ''
        );


    if ($promo === '') {

        throw new Exception(
            "Promotion name is required."
        );
    }


    if ($rate < 0) {

        throw new Exception(
            "Rate cannot be negative."
        );
    }


    if (
        $type !== 'individual' &&
        $type !== 'outlet_bundle'
    ) {

        throw new Exception(
            "Invalid promo type. Use individual or outlet_bundle."
        );
    }


    $status =
        getCalculatedPromotionStatus(
            $startDate,
            $endDate
        );


    $mysqli->begin_transaction();


    try {

        /*
        |--------------------------------------------------------------------------
        | INSERT PROMOTION
        |--------------------------------------------------------------------------
        */

        $stmt =
            $mysqli->prepare("
                INSERT INTO Incentives_information
                (
                    promo,
                    Rate,
                    type,
                    start_date,
                    end_date,
                    status
                )

                VALUES
                (?, ?, ?, ?, ?, ?)
            ");


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare promotion insert: " .
                $mysqli->error
            );
        }


        $stmt->bind_param(
            "sdssss",
            $promo,
            $rate,
            $type,
            $startDate,
            $endDate,
            $status
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Unable to add promotion: " .
                $stmt->error
            );
        }


        $promoId =
            $stmt->insert_id;


        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | INSERT BUNDLE INFORMATION
        |--------------------------------------------------------------------------
        */

        if ($type === 'outlet_bundle') {

            $items = [];


            for ($i = 1; $i <= 5; $i++) {

                $key1 =
                    "item_" . $i;

                $key2 =
                    "item" . $i;


                $value = '';


                if (isset($data[$key1])) {

                    $value =
                        cleanValue(
                            $data[$key1]
                        );

                } elseif (isset($data[$key2])) {

                    $value =
                        cleanValue(
                            $data[$key2]
                        );
                }


                $items[$i] =
                    $value;
            }


            $hasItem = false;


            for ($i = 1; $i <= 5; $i++) {

                if ($items[$i] !== '') {

                    $hasItem = true;

                    break;
                }
            }


            if ($hasItem) {

                $stmt =
                    $mysqli->prepare("
                        INSERT INTO Bundle_information
                        (
                            promo_id,
                            item_1,
                            item_2,
                            item_3,
                            item_4,
                            item_5
                        )

                        VALUES
                        (?, ?, ?, ?, ?, ?)
                    ");


                if (!$stmt) {

                    throw new Exception(
                        "Unable to prepare bundle insert: " .
                        $mysqli->error
                    );
                }


                $stmt->bind_param(
                    "isssss",
                    $promoId,
                    $items[1],
                    $items[2],
                    $items[3],
                    $items[4],
                    $items[5]
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Unable to add bundle items: " .
                        $stmt->error
                    );
                }


                $stmt->close();
            }
        }


        $mysqli->commit();


        return [

            "success" => true,

            "message" =>
                "Promotion added successfully.",

            "promo_id" =>
                $promoId
        ];

    } catch (Throwable $e) {

        $mysqli->rollback();

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| EDIT PROMOTION
|--------------------------------------------------------------------------
*/

function editPromotion(
    $mysqli,
    $data
) {

    $promoId =
        (int)(
            $data['promo_id'] ??
            0
        );


    $promo =
        cleanValue(
            $data['promo'] ??
            $data['promotion'] ??
            ''
        );


    $rate =
        (float)(
            $data['Rate'] ??
            $data['rate'] ??
            0
        );


    $type =
        normalizePromotionType(
            $data['type'] ?? ''
        );


    $startDate =
        cleanValue(
            $data['start_date'] ?? ''
        );


    $endDate =
        cleanValue(
            $data['end_date'] ?? ''
        );


    if ($promoId <= 0) {

        throw new Exception(
            "Invalid promotion ID."
        );
    }


    if ($promo === '') {

        throw new Exception(
            "Promotion name is required."
        );
    }


    if ($rate < 0) {

        throw new Exception(
            "Rate cannot be negative."
        );
    }


    if (
        $type !== 'individual' &&
        $type !== 'outlet_bundle'
    ) {

        throw new Exception(
            "Invalid promo type. Use individual or outlet_bundle."
        );
    }


    $status =
        getCalculatedPromotionStatus(
            $startDate,
            $endDate
        );


    $mysqli->begin_transaction();


    try {

        /*
        |--------------------------------------------------------------------------
        | UPDATE PROMOTION
        |--------------------------------------------------------------------------
        */

        $stmt =
            $mysqli->prepare("
                UPDATE Incentives_information

                SET

                    promo = ?,
                    Rate = ?,
                    type = ?,
                    start_date = ?,
                    end_date = ?,
                    status = ?

                WHERE
                    promo_id = ?
            ");


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare promotion update: " .
                $mysqli->error
            );
        }


        $stmt->bind_param(
            "sdssssi",
            $promo,
            $rate,
            $type,
            $startDate,
            $endDate,
            $status,
            $promoId
        );


        if (!$stmt->execute()) {

            throw new Exception(
                "Unable to update promotion: " .
                $stmt->error
            );
        }


        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | BUNDLE INFORMATION
        |--------------------------------------------------------------------------
        */

        if ($type === 'outlet_bundle') {

            $items = [];


            for ($i = 1; $i <= 5; $i++) {

                $key1 =
                    "item_" . $i;

                $key2 =
                    "item" . $i;


                $value = '';


                if (isset($data[$key1])) {

                    $value =
                        cleanValue(
                            $data[$key1]
                        );

                } elseif (isset($data[$key2])) {

                    $value =
                        cleanValue(
                            $data[$key2]
                        );
                }


                $items[$i] =
                    $value;
            }


            /*
            |--------------------------------------------------------------------------
            | Check existing bundle row
            |--------------------------------------------------------------------------
            */

            $stmt =
                $mysqli->prepare("
                    SELECT promo_id

                    FROM Bundle_information

                    WHERE promo_id = ?

                    LIMIT 1
                ");


            if (!$stmt) {

                throw new Exception(
                    "Unable to check bundle information."
                );
            }


            $stmt->bind_param(
                "i",
                $promoId
            );


            $stmt->execute();


            $result =
                $stmt->get_result();


            $exists =
                $result->num_rows > 0;


            $stmt->close();


            if ($exists) {

                /*
                |--------------------------------------------------------------------------
                | UPDATE EXISTING BUNDLE
                |--------------------------------------------------------------------------
                */

                $stmt =
                    $mysqli->prepare("
                        UPDATE Bundle_information

                        SET

                            item_1 = ?,
                            item_2 = ?,
                            item_3 = ?,
                            item_4 = ?,
                            item_5 = ?

                        WHERE
                            promo_id = ?
                    ");


                if (!$stmt) {

                    throw new Exception(
                        "Unable to prepare bundle update."
                    );
                }


                $stmt->bind_param(
                    "sssssi",
                    $items[1],
                    $items[2],
                    $items[3],
                    $items[4],
                    $items[5],
                    $promoId
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Unable to update bundle information: " .
                        $stmt->error
                    );
                }


                $stmt->close();

            } else {

                /*
                |--------------------------------------------------------------------------
                | INSERT NEW BUNDLE
                |--------------------------------------------------------------------------
                */

                $stmt =
                    $mysqli->prepare("
                        INSERT INTO Bundle_information
                        (
                            promo_id,
                            item_1,
                            item_2,
                            item_3,
                            item_4,
                            item_5
                        )

                        VALUES
                        (?, ?, ?, ?, ?, ?)
                    ");


                if (!$stmt) {

                    throw new Exception(
                        "Unable to prepare bundle insert."
                    );
                }


                $stmt->bind_param(
                    "isssss",
                    $promoId,
                    $items[1],
                    $items[2],
                    $items[3],
                    $items[4],
                    $items[5]
                );


                if (!$stmt->execute()) {

                    throw new Exception(
                        "Unable to insert bundle information: " .
                        $stmt->error
                    );
                }


                $stmt->close();
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | If changed from bundle to individual,
            | remove bundle information.
            |--------------------------------------------------------------------------
            */

            $stmt =
                $mysqli->prepare("
                    DELETE FROM Bundle_information
                    WHERE promo_id = ?
                ");


            if ($stmt) {

                $stmt->bind_param(
                    "i",
                    $promoId
                );

                $stmt->execute();

                $stmt->close();
            }
        }


        $mysqli->commit();


        return [

            "success" => true,

            "message" =>
                "Promotion updated successfully."
        ];

    } catch (Throwable $e) {

        $mysqli->rollback();

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| ADD USER
|--------------------------------------------------------------------------
*/

function addUser(
    $mysqli,
    $data
) {

    $staffName =
        cleanValue(
            $data['staff_name'] ??
            $data['name'] ??
            ''
        );


    $sales_man_code =
        cleanValue(
            $data['sales_man_code'] ??
            ''
        );


    $outlet =
        cleanValue(
            $data['outlet'] ??
            ''
        );


    $role =
        strtolower(
            cleanValue(
                $data['role'] ??
                'staff'
            )
        );


    if ($staffName === '') {

        throw new Exception(
            "Staff name is required."
        );
    }


    if ($sales_man_code === '') {

        throw new Exception(
            "Sales man code is required."
        );
    }


    if ($outlet === '') {

        throw new Exception(
            "Outlet is required."
        );
    }


    if (
        $role !== 'staff' &&
        $role !== 'admin'
    ) {
        $role = 'staff';
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK DUPLICATE sales_man_code
    |--------------------------------------------------------------------------
    */

    $stmt =
        $mysqli->prepare("
            SELECT sales_man_code

            FROM staff_details

            WHERE sales_man_code = ?

            LIMIT 1
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to check user."
        );
    }


    $stmt->bind_param(
        "s",
        $sales_man_code
    );


    $stmt->execute();


    $result =
        $stmt->get_result();


    $exists =
        $result->num_rows > 0;


    $stmt->close();


    if ($exists) {

        throw new Exception(
            "Sales man code already exists."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT USER
    |--------------------------------------------------------------------------
    */

    $stmt =
        $mysqli->prepare("
            INSERT INTO staff_details
            (
                sales_man_code,
                staff_name,
                outlet,
                role
            )

            VALUES
            (?, ?, ?, ?)
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare user insert."
        );
    }


    $stmt->bind_param(
        "ssss",
        $sales_man_code,
        $staffName,
        $outlet,
        $role
    );


    if (!$stmt->execute()) {

        throw new Exception(
            "Unable to add user: " .
            $stmt->error
        );
    }


    $stmt->close();


    return [

        "success" => true,

        "message" =>
            "User added successfully."
    ];
}


/*
|--------------------------------------------------------------------------
| DELETE USER
|--------------------------------------------------------------------------
*/

function deleteUser(
    $mysqli,
    $sales_man_code
) {

    $sales_man_code =
        cleanValue($sales_man_code);


    if ($sales_man_code === '') {

        throw new Exception(
            "Invalid sales man code."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check existing submissions
    |--------------------------------------------------------------------------
    */

    $stmt =
        $mysqli->prepare("
            SELECT COUNT(*) AS total

            FROM staff_incentives

            WHERE sales_man_code = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to check user submissions."
        );
    }


    $stmt->bind_param(
        "s",
        $sales_man_code
    );


    $stmt->execute();


    $result =
        $stmt->get_result();


    $row =
        $result->fetch_assoc();


    $stmt->close();


    $submissionCount =
        (int)($row['total'] ?? 0);


    if ($submissionCount > 0) {

        throw new Exception(
            "This user cannot be deleted because submissions already exist."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    $stmt =
        $mysqli->prepare("
            DELETE FROM staff_details

            WHERE sales_man_code = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare user deletion."
        );
    }


    $stmt->bind_param(
        "s",
        $sales_man_code
    );


    $stmt->execute();


    $affected =
        $stmt->affected_rows;


    $stmt->close();


    if ($affected === 0) {

        throw new Exception(
            "User not found."
        );
    }


    return [

        "success" => true,

        "message" =>
            "User deleted successfully."
    ];
}


/*
|--------------------------------------------------------------------------
| DELETE SUBMISSION
|--------------------------------------------------------------------------
*/

function deleteSubmission(
    $mysqli,
    $id
) {

    $id =
        (int)$id;


    if ($id <= 0) {

        throw new Exception(
            "Invalid submission ID."
        );
    }


    $stmt =
        $mysqli->prepare("
            DELETE FROM staff_incentives

            WHERE incentive_id = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to prepare submission deletion."
        );
    }


    $stmt->bind_param(
        "i",
        $id
    );


    $stmt->execute();


    $affected =
        $stmt->affected_rows;


    $stmt->close();


    if ($affected === 0) {

        throw new Exception(
            "Submission not found."
        );
    }


    return [

        "success" => true,

        "message" =>
            "Submission deleted successfully."
    ];
}


/*
|--------------------------------------------------------------------------
| DELETE PROMOTION
|--------------------------------------------------------------------------
*/

function deletePromotion(
    $mysqli,
    $promoId
) {

    $promoId =
        (int)$promoId;


    if ($promoId <= 0) {

        throw new Exception(
            "Invalid promotion ID."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Check submissions
    |--------------------------------------------------------------------------
    */

    $stmt =
        $mysqli->prepare("
            SELECT COUNT(*) AS total

            FROM staff_incentives

            WHERE promo_id = ?
        ");


    if (!$stmt) {

        throw new Exception(
            "Unable to check promotion submissions."
        );
    }


    $stmt->bind_param(
        "i",
        $promoId
    );


    $stmt->execute();


    $result =
        $stmt->get_result();


    $row =
        $result->fetch_assoc();


    $stmt->close();


    $submissionCount =
        (int)($row['total'] ?? 0);


    if ($submissionCount > 0) {

        throw new Exception(
            "This promotion cannot be deleted because submissions already exist."
        );
    }


    $mysqli->begin_transaction();


    try {

        /*
        |--------------------------------------------------------------------------
        | Delete bundle information
        |--------------------------------------------------------------------------
        */

        $stmt =
            $mysqli->prepare("
                DELETE FROM Bundle_information

                WHERE promo_id = ?
            ");


        if ($stmt) {

            $stmt->bind_param(
                "i",
                $promoId
            );

            $stmt->execute();

            $stmt->close();
        }


        /*
        |--------------------------------------------------------------------------
        | Delete promotion
        |--------------------------------------------------------------------------
        */

        $stmt =
            $mysqli->prepare("
                DELETE FROM Incentives_information

                WHERE promo_id = ?
            ");


        if (!$stmt) {

            throw new Exception(
                "Unable to prepare promotion deletion."
            );
        }


        $stmt->bind_param(
            "i",
            $promoId
        );


        $stmt->execute();


        $affected =
            $stmt->affected_rows;


        $stmt->close();


        if ($affected === 0) {

            throw new Exception(
                "Promotion not found."
            );
        }


        $mysqli->commit();


        return [

            "success" => true,

            "message" =>
                "Promotion deleted successfully."
        ];

    } catch (Throwable $e) {

        $mysqli->rollback();

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| CURRENT ADMIN
|--------------------------------------------------------------------------
*/

function getCurrentAdmin()
{
    return [

        "staff_name" =>
            $_SESSION['staff_name'] ?? '',

        "sales_man_code" =>
            $_SESSION['sales_man_code'] ?? '',

        "outlet" =>
            $_SESSION['outlet'] ?? '',

        "role" =>
            $_SESSION['role'] ?? ''
    ];
}


/*
|--------------------------------------------------------------------------
| UPDATE PROMOTION STATUS
|--------------------------------------------------------------------------
*/

syncPromotionStatuses($mysqli);


/*
|--------------------------------------------------------------------------
| ACTION ROUTER
|--------------------------------------------------------------------------
*/

try {

    switch ($action) {


        /*
        |--------------------------------------------------------------------------
        | BOOTSTRAP
        |--------------------------------------------------------------------------
        */

        case 'bootstrap':

            $submissions =
                fetchSubmissions($mysqli);


            $promotions =
                fetchPromotions($mysqli);


            $users =
                fetchUsers($mysqli);


            $dashboard =
                buildDashboard(
                    $mysqli,
                    $submissions
                );


            $monthlySummary =
                buildMonthlySummary(
                    $mysqli
                );


            $top3 =
                buildTop3(
                    $mysqli
                );


            response([

                "success" => true,

                "admin" =>
                    getCurrentAdmin(),

                "dashboard" =>
                    $dashboard,

                "submissions" =>
                    $submissions,

                "promotions" =>
                    $promotions,

                "users" =>
                    $users,

                "monthlySummary" =>
                    $monthlySummary,

                "monthly_summary" =>
                    $monthlySummary,

                "top3" =>
                    $top3
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        case 'dashboard':

            $submissions =
                fetchSubmissions($mysqli);


            response([

                "success" => true,

                "dashboard" =>
                    buildDashboard(
                        $mysqli,
                        $submissions
                    )
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | GET SUBMISSION
        |--------------------------------------------------------------------------
        */

        case 'get_submission':
        case 'submission':

            $id =
                (int)(
                    $_GET['id'] ??
                    $_GET['incentive_id'] ??
                    0
                );


            $submission =
                getSubmission(
                    $mysqli,
                    $id
                );


            if (!$submission) {

                response([

                    "success" => false,

                    "message" =>
                        "Submission not found."
                ], 404);
            }


            response([

                "success" => true,

                "submission" =>
                    $submission
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | GET ALL SUBMISSIONS
        |--------------------------------------------------------------------------
        */

        case 'get_submissions':
        case 'submissions':

            response([

                "success" => true,

                "submissions" =>
                    fetchSubmissions($mysqli)
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | STAFF BREAKDOWN
        |--------------------------------------------------------------------------
        */

        case 'staff_promotion_breakdown':

            $sales_man_code =
                cleanValue(
                    $_GET['sales_man_code'] ??
                    $_GET['sales_man_code'] ??
                    ''
                );


            response([

                "success" => true,

                "data" =>
                    getStaffPromotionBreakdown(
                        $mysqli,
                        $sales_man_code
                    )
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | APPROVE
        |--------------------------------------------------------------------------
        */

        case 'approve':

            $data =
                getJsonInput();


            $id =
                (int)(
                    $data['incentive_id'] ??
                    $data['id'] ??
                    $_GET['id'] ??
                    0
                );


            response(
                approveSubmission(
                    $mysqli,
                    $id
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | REJECT
        |--------------------------------------------------------------------------
        */

        case 'reject':

            $data =
                getJsonInput();


            $id =
                (int)(
                    $data['incentive_id'] ??
                    $data['id'] ??
                    $_GET['id'] ??
                    0
                );


            $reason =
                cleanValue(
                    $data['rejection_reason'] ??
                    $data['reason'] ??
                    ''
                );


            response(
                rejectSubmission(
                    $mysqli,
                    $id,
                    $reason
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | EDIT SUBMISSION
        |--------------------------------------------------------------------------
        */

        case 'edit_submission':

            $data =
                getJsonInput();


            response(
                editSubmission(
                    $mysqli,
                    $data
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | DELETE SUBMISSION
        |--------------------------------------------------------------------------
        */

        case 'delete_submission':

            $data =
                getJsonInput();


            $id =
                (int)(
                    $data['incentive_id'] ??
                    $data['id'] ??
                    $_GET['id'] ??
                    0
                );


            response(
                deleteSubmission(
                    $mysqli,
                    $id
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | PROMOTIONS
        |--------------------------------------------------------------------------
        */

        case 'get_promotions':
        case 'promotions':

            response([

                "success" => true,

                "promotions" =>
                    fetchPromotions($mysqli)
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | ADD PROMOTION
        |--------------------------------------------------------------------------
        */

        case 'add_promotion':

            $data =
                getJsonInput();


            response(
                addPromotion(
                    $mysqli,
                    $data
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | EDIT PROMOTION
        |--------------------------------------------------------------------------
        */

        case 'edit_promotion':

            $data =
                getJsonInput();


            response(
                editPromotion(
                    $mysqli,
                    $data
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | DELETE PROMOTION
        |--------------------------------------------------------------------------
        */

        case 'delete_promotion':

            $data =
                getJsonInput();


            $promoId =
                (int)(
                    $data['promo_id'] ??
                    $data['id'] ??
                    $_GET['promo_id'] ??
                    $_GET['id'] ??
                    0
                );


            response(
                deletePromotion(
                    $mysqli,
                    $promoId
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | USERS
        |--------------------------------------------------------------------------
        */

        case 'get_users':
        case 'users':

            response([

                "success" => true,

                "users" =>
                    fetchUsers($mysqli)
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | ADD USER
        |--------------------------------------------------------------------------
        */

        case 'add_user':

            $data =
                getJsonInput();


            response(
                addUser(
                    $mysqli,
                    $data
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | DELETE USER
        |--------------------------------------------------------------------------
        */

        case 'delete_user':

            $data =
                getJsonInput();


            $sales_man_code =
                cleanValue(
                    $data['sales_man_code'] ??           
                    $_GET['sales_man_code'] ??
                    ''
                );


            response(
                deleteUser(
                    $mysqli,
                    $sales_man_code
                )
            );

            break;


        /*
        |--------------------------------------------------------------------------
        | MONTHLY SUMMARY
        |--------------------------------------------------------------------------
        */

        case 'monthly_summary':
        case 'get_monthly_summary':

            $month =
                cleanValue(
                    $_GET['month'] ??
                    ''
                );


            $summary =
                buildMonthlySummary(
                    $mysqli,
                    $month
                );


            response([

                "success" => true,

                "monthlySummary" =>
                    $summary,

                "monthly_summary" =>
                    $summary
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | TOP 3
        |--------------------------------------------------------------------------
        */

        case 'top3':
        case 'get_top3':

            $month =
                cleanValue(
                    $_GET['month'] ??
                    ''
                );


            response([

                "success" => true,

                "top3" =>
                    buildTop3(
                        $mysqli,
                        $month
                    )
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | CURRENT ADMIN
        |--------------------------------------------------------------------------
        */

        case 'get_current_admin':

            response([

                "success" => true,

                "admin" =>
                    getCurrentAdmin()
            ]);

            break;


        /*
        |--------------------------------------------------------------------------
        | INVALID ACTION
        |--------------------------------------------------------------------------
        */

        default:

            response([

                "success" => false,

                "message" =>
                    "Invalid or missing action."
            ], 400);

            break;
    }


} catch (Throwable $e) {

    error_log(
        "admin.php error: " .
        $e->getMessage() .
        " in " .
        $e->getFile() .
        " on line " .
        $e->getLine()
    );


    response([

        "success" => false,

        "message" =>
            $e->getMessage()
    ], 500);
}


if ($mysqli) {
    $mysqli->close();
}

?>