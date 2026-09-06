<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * A cache that can be told which dataset the asking question is about.
 *
 * WHY THIS IS SEPARATE FROM QueryCacheInterface
 *
 * `find()` keys on the question's TEXT. That is fine until a caller supplies
 * a dataset out of band -  the widget's `dataset="orders"` prop, or the API's
 * dataset field -  because then two questions with identical wording are two
 * different questions. "What is the total" on an orders page and the same
 * words on a products page hash the same, hit the same row, and the second
 * one is answered with the first one's number. No provider call, nothing in
 * a log, and the answer looks exactly like a correct one.
 *
 * The fix needs the asking dataset to reach the cache, and the obvious way
 * to do that -  add a parameter to `find()` -  cannot be done at all. PHP
 * requires an implementing class to match the interface signature, so adding
 * even an optional parameter makes every third-party cache fatal on load.
 * `Contracts\ReportsUsage` solved the same problem the same way when token
 * reporting was added: a separate, optional interface, checked with
 * `instanceof`, degrading gracefully when absent.
 *
 * THIS IS AN OPTIMISATION, NOT THE SAFETY MECHANISM
 *
 * Worth being exact about, because the first version of this got it wrong in
 * an expensive way. Eligibility does not depend on this interface: every row
 * records the scope its question was asked under, inside the intent blob that
 * `store()` receives and `find()` returns, and QueryOrchestrator checks it on
 * every hit regardless of which method produced the row. A cache that cannot
 * scope is therefore no less safe than one that can.
 *
 * What this interface buys is narrowing -  an implementation that knows the
 * asking dataset can filter its own candidates instead of returning ones the
 * engine will discard. The bundled cache folds the hint into the row hash, so
 * two pages scoped to different datasets never share a row for the same
 * wording; a custom implementation that matches on wording needs it far more,
 * which is what the $datasetHint notes below are about.
 *
 * The engine used to BYPASS a cache that did not implement this whenever a
 * dataset was known, on the theory that skipping cost one API call and a
 * cross-dataset hit cost a wrong number. The theory was right and the
 * conclusion was not: `resolveAskingDataset()` returns the sole registered
 * key unconditionally on a single-dataset install, so the bypass fired on
 * every question and every replacement cache silently did nothing at all,
 * while `store()` kept filling it up.
 */
interface ScopesCacheByDataset
{
    /**
     * Find a cached result for a query asked about a specific dataset.
     *
     * @param  string  $query  The natural language query
     * @param  string|null  $datasetHint  The dataset THIS question resolves to,
     *                                    when it is known at zero API cost -  an explicit hint,
     *                                    conversation state, or keyword detection on the question's own
     *                                    text. Never the dataset of a candidate row: that is the thing
     *                                    being checked, not the thing doing the checking. With no hint,
     *                                    an implementation must refuse a fuzzy match rather than guess
     *                                    which dataset the asking question was about.
     * @return array|null Cached intent data, or null if not found
     */
    public function findForDataset(string $query, ?string $datasetHint = null): ?array;
}
