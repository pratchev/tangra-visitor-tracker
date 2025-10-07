(function($){
  let chartDaily, chartPages;

  function showNoData(canvasId, msg){
    const container = document.getElementById(canvasId + '_container');
    if(!container) {
      console.error(`Container ${canvasId}_container not found`);
      return;
    }
    container.innerHTML = '<div style="min-height:160px;display:flex;align-items:center;justify-content:center;color:#6b7280;border:1px dashed #e5e7eb;border-radius:8px;background-color:#fff;">' 
      + (msg || 'No data for the selected filters') + '</div>';
  }

  function initializeCanvas(id) {
    const container = document.getElementById(id + '_container');
    if (!container) {
      console.error(`Container ${id}_container not found`);
      return false;
    }

    const canvas = document.createElement('canvas');
    canvas.id = id;
    canvas.className = 'tvt-canvas skip-lazy no-lazyload';
    canvas.setAttribute('data-no-lazy', '1');
    canvas.setAttribute('data-nitro-lazy', 'off');
    canvas.style.width = '100%';
    canvas.style.minHeight = '160px';

    container.innerHTML = '';
    container.appendChild(canvas);
    console.log(`Canvas ${id} initialized`);
    return true;
  }

  function fetchAndRenderStats() {
    const from = $('#tvt_from').val();
    const to = $('#tvt_to').val();
    const event = $('#tvt_event').val();
    const guests = $('#tvt_guests').is(':checked') ? 1 : 0;

    console.log('Fetching stats with params:', { from, to, event, guests });

    // Show loading state
    ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
      showNoData(id, 'Loading data...');
    });

    $.ajax({
      url: TVT_ANALYTICS.ajax,
      method: 'POST',
      data: {
        action: 'tvt_fetch_stats',
        nonce: TVT_ANALYTICS.nonce,
        from, to, event, guests
      },
      dataType: 'json',
      success: function(res) {
        console.log('Raw response:', res);

        if (!res || !res.success) {
          const msg = res?.data?.message || 'Failed to load data';
          ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
            showNoData(id, msg);
          });
          return;
        }

        // Initialize canvases first
        const dailyReady = initializeCanvas('tvt_chart_daily');
        const pagesReady = initializeCanvas('tvt_chart_pages');

        if (dailyReady && pagesReady) {
          renderCharts(res.data);
        }
      },
      error: function(xhr, status, error) {
        console.error('Ajax failed:', { status, error, response: xhr.responseText });
        ['tvt_chart_daily', 'tvt_chart_pages'].forEach(id => {
          showNoData(id, 'Error loading data');
        });
      }
    });
  }

  function renderCharts(data) {
    if (typeof Chart === 'undefined') {
      console.error('Chart.js not loaded');
      return;
    }

    // Render KPIs
    const kpis = data.kpis || {};
    $('#kpi_total').text(kpis.total || 0);
    $('#kpi_unique').text(kpis.unique || 0);
    $('#kpi_views').text(kpis.views || 0);
    $('#kpi_logins').text(kpis.logins || 0);

    // Render daily chart
    const dailyCtx = document.getElementById('tvt_chart_daily');
    if (dailyCtx) {
      if (chartDaily) chartDaily.destroy();
      
      const dailyData = data.daily || [];
      if (dailyData.length) {
        chartDaily = new Chart(dailyCtx, {
          type: 'line',
          data: {
            labels: dailyData.map(x => x.day),
            datasets: [{
              label: 'Events',
              data: dailyData.map(x => x.count),
              borderColor: '#3b82f6',
              backgroundColor: 'rgba(59, 130, 246, 0.1)',
              fill: true,
              tension: 0.3
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              y: {
                beginAtZero: true,
                ticks: { precision: 0 }
              }
            }
          }
        });
      } else {
        showNoData('tvt_chart_daily', 'No events in this date range');
      }
    }

    // Render pages chart
    const pagesCtx = document.getElementById('tvt_chart_pages');
    if (pagesCtx) {
      if (chartPages) chartPages.destroy();
      
      const pagesData = data.top_pages || [];
      if (pagesData.length) {
        chartPages = new Chart(pagesCtx, {
          type: 'bar',
          data: {
            labels: pagesData.map(x => x.url),
            datasets: [{
              label: 'Hits',
              data: pagesData.map(x => x.count),
              backgroundColor: '#3b82f6'
            }]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              y: {
                ticks: {
                  callback: v => {
                    const label = pagesData[v]?.url || '';
                    return label.length > 60 ? label.slice(0, 57) + '...' : label;
                  }
                }
              }
            }
          }
        });
      } else {
        showNoData('tvt_chart_pages', 'No page hits in this date range');
      }
    }
  }

  // Set up form handlers
  $('#tvt-analytics-form').on('submit', function(e) {
    e.preventDefault();
    fetchAndRenderStats();
  });

  // Set initial date range
  const today = TVT_ANALYTICS.today;
  const d = new Date(today);
  const to = today;
  const fromDate = new Date(d.getTime() - 29*24*60*60*1000);
  const fmt = x => x.toISOString().slice(0,10);
  $('#tvt_to').val(to);
  $('#tvt_from').val(fmt(fromDate));

  // Initial load
  $(document).ready(fetchAndRenderStats);

})(jQuery);