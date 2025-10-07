(function($){
  let chartDaily, chartPages;

  function showNoData(canvasId, msg){
    const el = document.getElementById(canvasId);
    if(!el) return;
    const parent = el.parentElement;
    parent.style.position = 'relative';
    parent.innerHTML = '<div style="min-height:160px;display:flex;align-items:center;justify-content:center;color:#6b7280;border:1px dashed #e5e7eb;border-radius:8px;">'
      + (msg || 'No data for the selected filters') + '</div>';
  }

  function chartSafe(cb, fallbackId){
    try{
      if (typeof Chart === 'undefined'){
        showNoData(fallbackId, 'Charts unavailable (Chart.js blocked).');
        return false;
      }
      cb();
      return true;
    }catch(e){
      console.error(e);
      showNoData(fallbackId, 'Unable to render chart.');
      return false;
    }
  }

  function renderKPIs(k){
    $('#kpi_total').text(k.total||0);
    $('#kpi_unique').text(k.unique||0);
    $('#kpi_views').text(k.views||0);
    $('#kpi_logins').text(k.logins||0);
  }

  function renderDaily(d){
    const labels = (d||[]).map(x=>x.day);
    const data   = (d||[]).map(x=>x.count);

    if(!labels.length){
      showNoData('tvt_chart_daily', 'No events in this date range');
      return;
    }

    chartSafe(()=>{
      const ctx = document.getElementById('tvt_chart_daily');
      if(chartDaily) chartDaily.destroy();
      chartDaily = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets: [{ label: 'Events', data }] },
        options: { responsive: true, maintainAspectRatio: false }
      });
    }, 'tvt_chart_daily');
  }

  function renderPages(d){
    const rows = d||[];
    const labels = rows.map(x=>x.url);
    const data   = rows.map(x=>x.count);

    if(!labels.length){
      showNoData('tvt_chart_pages', 'No page hits in this date range');
      return;
    }

    chartSafe(()=>{
      const ctx = document.getElementById('tvt_chart_pages');
      if(chartPages) chartPages.destroy();
      chartPages = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Hits', data }] },
        options: {
          indexAxis: 'y',
          responsive: true, maintainAspectRatio: false,
          scales: { y: { ticks: { callback: v => (labels[v]||'').slice(0,60) } } }
        }
      });
    }, 'tvt_chart_pages');
  }

  function fetchStats(){
    const from = $('#tvt_from').val();
    const to   = $('#tvt_to').val();
    const event= $('#tvt_event').val();
    const guests = $('#tvt_guests').is(':checked') ? 1 : 0;

    $.post(TVT_ANALYTICS.ajax, {
      action: 'tvt_fetch_stats',
      nonce: TVT_ANALYTICS.nonce,
      from, to, event, guests
    }, function(res){
      if(!res || !res.success) return;

      // store last data for re-render
      window.__tvt_last_daily = res.data.daily || [];
      window.__tvt_last_pages = res.data.top_pages || [];

      renderKPIs(res.data.kpis || {});
      renderDaily(window.__tvt_last_daily);
      renderPages(window.__tvt_last_pages);
    });
  }

  // Protect canvases from lazyload/DOM swaps
  function protectCanvas(id, rerender){
    const el = document.getElementById(id);
    if(!el) return;
    const holder = el.parentElement;

    const rebuild = () => {
      const current = document.getElementById(id);
      if (!current || current.nodeName !== 'CANVAS') {
        const canvas = document.createElement('canvas');
        canvas.id = id;
        canvas.className = 'tvt-canvas skip-lazy no-lazyload';
        canvas.setAttribute('data-no-lazy','1');
        canvas.setAttribute('data-nitro-lazy','off');
        canvas.style.minHeight = '160px';
        holder.innerHTML = '';
        holder.appendChild(canvas);
        rerender();
      }
    };

    const mo = new MutationObserver(rebuild);
    mo.observe(holder, { childList: true });

    document.addEventListener('visibilitychange', () => { if (!document.hidden) rebuild(); });
  }

  $('#tvt-analytics-form').on('submit', function(e){
    e.preventDefault();
    fetchStats();
  });

  // Default last 30 days
  const today = TVT_ANALYTICS.today;
  const d = new Date(today);
  const to = today;
  const fromDate = new Date(d.getTime() - 29*24*60*60*1000);
  const fmt = x => x.toISOString().slice(0,10);
  $('#tvt_to').val(to);
  $('#tvt_from').val(fmt(fromDate));

  // Init
  fetchStats();
  protectCanvas('tvt_chart_daily', () => renderDaily(window.__tvt_last_daily || []));
  protectCanvas('tvt_chart_pages', () => renderPages(window.__tvt_last_pages || []));
})(jQuery);
