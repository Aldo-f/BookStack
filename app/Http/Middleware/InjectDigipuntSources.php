<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use BookStack\Entities\Models\Page;

class InjectDigipuntSources
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only inject on page views, not API
        if ($request->route() && !$request->routeIs('api.*')) {
            $pageSlug = $request->route('pageSlug');
            
            if ($pageSlug) {
                // Find page by slug using the Page model
                $page = Page::where('slug', $pageSlug)->first();
                
                if ($page) {
                    $pageId = $page->id;
                    
                    // Check if page has any sources linked
                    $sourceCount = DB::table('entity_source_links')
                        ->where('entity_type', 'page')
                        ->where('entity_id', $pageId)
                        ->count();
                    
                    if ($sourceCount > 0) {
                        $html = $response->getContent();
                        
                        // Extract CSP nonce from existing script tags
                        $nonce = '';
                        if (preg_match('/nonce="([A-Za-z0-9+\/=]+)"/', $html, $m)) {
                            $nonce = $m[1];
                        }
                        
                        $nonceAttr = $nonce ? ' nonce="' . $nonce . '"' : '';
                        
                        $injectionHtml = '<div id="digipunt-sources" class="digipunt-sources" data-entity-type="page" data-entity-id="' . (int)$pageId . '"></div>
<div id="digipunt-sources-list" class="digipunt-sources-list"></div>
<script src="/theme/digipunt/js/sources.js?v=11"' . $nonceAttr . '></script>';
                        
                        // Inject just above the sibling-navigation element (before it)
                        $siblingPos = strpos($html, '<div id="sibling-navigation"');
                        if ($siblingPos !== false) {
                            $html = substr_replace($html, $injectionHtml . "\n", $siblingPos, 0);
                        } else {
                            // Fallback: before </body> if sibling-navigation not found
                            $html = str_replace('</body>', $injectionHtml . '</body>', $html);
                        }
                        $response->setContent($html);
                    }
                }
            }
        }

        return $response;
    }
}
