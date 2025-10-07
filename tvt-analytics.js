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
        console.error('Chart.js not loaded');
        showNoData(fallbackId, 'Charts unavailable (Chart.js not loaded).');
        return false;
      }

      // Check if canvas exists and is ready
      const canvas = document.getElementById(fallbackId);
      if(!canvas) {
        console.error('Canvas element not found:', fallbackId);
        return false;
      }

      // Ensure canvas is properly sized
      const parent = canvas.parentElement;
      if(parent) {
        canvas.style.width = '100%';
        canvas.style.height = parent.clientHeight + 'px';
      }

      // Execute callback with error boundary
      const result = cb();
      console.log('Chart rendered successfully for:', fallbackId);
      return true;
    }catch(e){
      console.error('Chart rendering failed:', e);
      showNoData(fallbackId, 'Unable to render chart: ' + e.message);
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
    console.log('Rendering daily chart with data:', d); // Debug log
    
    if(!Array.isArray(d)) {
      console.error('Daily data is not an array:', d);
      showNoData('tvt_chart_daily', 'Invalid data received');
      return;
    }

    const labels = d.map(x => x.day);
    const data = d.map(x => x.count);

    if(!labels.length){
      showNoData('tvt_chart_daily', 'No events in this date range');
      return;
    }

    chartSafe(()=>{
      const ctx = document.getElementById('tvt_chart_daily');
      if(!ctx) {
        console.error('Canvas context not found for daily chart');
        return;
      }

      if(chartDaily) {
        console.log('Destroying existing daily chart'); // Debug log
        chartDaily.destroy();
      }

      chartDaily = new Chart(ctx, {
        type: 'line',
        data: { 
          labels, 
          datasets: [{ 
            label: 'Events',
            data,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 2,
            tension: 0.3,
            fill: true
          }] 
        },
        options: { 
          responsive: true, 
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: 'top'
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                precision: 0
              }
            }
          }
        }
      });
      console.log('Daily chart created successfully'); // Debug log
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

    // Show loading state
    ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
      showNoData(id, 'Loading data...');
    });

    $.post(TVT_ANALYTICS.ajax, {
      action: 'tvt_fetch_stats',
      nonce: TVT_ANALYTICS.nonce,
      from, to, event, guests
    }, function(res){
      if(!res || !res.success) {
        console.error('Failed to fetch stats:', res);
        ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
          showNoData(id, 'Failed to load data');
        });
        return;
      }

      console.log('Received stats data:', res.data); // Debug log

      // Validate received data
      if(!res.data || typeof res.data !== 'object') {
        console.error('Invalid data format received');
        return;
      }

      // store last data for re-render
      window.__tvt_last_daily = Array.isArray(res.data.daily) ? res.data.daily : [];
      window.__tvt_last_pages = Array.isArray(res.data.top_pages) ? res.data.top_pages : [];

      renderKPIs(res.data.kpis || {});
      
      // Ensure Chart.js is loaded before rendering
      if(typeof Chart !== 'undefined') {
        renderDaily(window.__tvt_last_daily);
        renderPages(window.__tvt_last_pages);
      } else {
        console.error('Chart.js not loaded when attempting to render');
        ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
          showNoData(id, 'Chart.js failed to load');
        });
      }
    }).fail(function(xhr, status, error) {
      console.error('Ajax request failed:', status, error);
      ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
        showNoData(id, 'Failed to fetch data from server');
      });
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
        canvas.setAttribute('data-lazy','false');
        canvas.style.minHeight = '160px';
        holder.innerHTML = '';
        holder.appendChild(canvas);
        // Add a small delay to ensure Chart.js is ready
        setTimeout(() => {
          if (typeof Chart !== 'undefined') {
            rerender();
          } else {
            showNoData(id, 'Chart.js is still loading...');
          }
        }, 100);
      }
    };

    // Initial build with retry mechanism
    let retries = 0;
    const initialBuild = () => {
      if (typeof Chart === 'undefined' && retries < 5) {
        retries++;
        setTimeout(initialBuild, 500);
        return;
      }
      rebuild();
    };
    initialBuild();

    const mo = new MutationObserver((mutations) => {
      // Only rebuild if the canvas was actually removed or replaced
      if (mutations.some(m => Array.from(m.removedNodes).some(n => n.id === id))) {
        rebuild();
      }
    });
    mo.observe(holder, { childList: true });

    document.addEventListener('visibilitychange', () => { 
      if (!document.hidden) {
        setTimeout(rebuild, 100);
      }
    });
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
