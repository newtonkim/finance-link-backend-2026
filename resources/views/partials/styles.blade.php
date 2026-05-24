   <title>Savings Accounts Report</title>

   <style>
      body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #333;
        }

        .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:20px;
            border-bottom:1px solid #ddd;
            padding-bottom:15px;
        }

        .logo {
            max-height:80px;
            max-width:120px;
            object-fit:contain;
        }

        .company {
            text-align:right;
        }

        .company h2 {
            margin:0;
            font-size:20px;
            font-weight:bold;
        }

        .company .tagline {
            font-size:12px;
            color:#6b7280;
            margin-top:4px;
            font-style:italic;
        }

        .meta {
            margin-bottom:10px;
        }

        .meta div {
            margin-bottom:3px;
        }
 

        .text-right {
            text-align:right;
        }

        .footer {
            margin-top:15px;
            font-size:10px;
            text-align:center;
            color:#777;
        }
       :root {
           --primary: #0A2318;
           --accent: #FCDC04;
           --action: #39B588;
           --bg: #f2f6f5;
           --border: #E5E7EB;
           --text-muted: #6B7280;
       }

       body {
           font-family: 'Segoe UI', Tahoma, sans-serif;
           font-size: 13px;
           background: var(--bg);
           color: var(--primary);
           padding: 20px;
       }

       /* Header */
       .report-header {
           background: white;
           padding: 15px 20px;
           border-radius: 10px;
           margin-bottom: 15px;
           border: 1px solid var(--border);
           text-align: center;
           align-items: center;
           align-self: center;

       }

       .report-header h2 {
           margin: 0;
       }

       .sub-title {
           color: var(--text-muted);
           margin-top: 4px;
       }

       /* Actions */
       .actions {
           margin-bottom: 10px;
           text-align: right;
       }

       button {
           background: var(--action);
           color: white;
           border: none;
           padding: 8px 14px;
           cursor: pointer;
           border-radius: 6px;
           font-weight: 500;
       }

       button:hover {
           opacity: 0.9;
       }

       /* Table container */
       .table-card {
           background: white;
           border-radius: 10px;
           overflow: hidden;
           border: 1px solid var(--border);
       }

       /* Table */
       table {
           width: 100%;
           border-collapse: collapse;
           table-layout: auto;
           /* auto-adjust columns */
       }

       table th,
       table td {
           padding: 0 5px;
           border-bottom: 1px solid var(--border);
           word-wrap: break-all;
           /* wrap long content */
           /* word-wrap: break-word; wrap long content */
       }

       table th,th>* {
           background: rgb(36, 35, 35);
           /* background: var(--primary); */
           color: white;
           font-weight: 600;
           font-size: 12px;
           text-transform: uppercase;
       }

       table tbody tr:hover {
           background: #f9fafb;
       }

       /* Alignment */
       .text-right {
           text-align: right;
       }

       /* Status Badge */
       .badge {
           padding: 4px 8px;
           border-radius: 20px;
           font-size: 11px;
           font-weight: 600;
       }

       .badge.active {
           background: #DCFCE7;
           color: #166534;
       }

       .badge.inactive {
           background: #FEE2E2;
           color: #991B1B;
       }

       /* Footer */
       tfoot th {
           background: var(--primary);
           color: white;
           font-size: 13px;
       }

       /* Empty */
       .empty {
           text-align: center;
           color: var(--text-muted);
           padding: 15px;
       }

       /* Fix date column */
       th:nth-child(8),
       td:nth-child(8) {
           /* width: 120px; */
           white-space: nowrap;
           /* prevent cutting */
       }



       @media print {
           body {
               background: white;
               padding: 0;
           }

           .no-print {
               display: none;
           }

           .report-header {
               border: none;
           }

           .table-card {
               border: none;
           }
       }
   </style>
