import Table from "@/Components/Dashboard/Table";
import { formatCurrency } from "./format";

export default function TopSellingProductsTable({ topSellingProducts }) {
    return (
        <Table.Card title="Top Selling Products">
            <Table>
                <Table.Thead>
                    <tr>
                        <Table.Th>Produk</Table.Th>
                        <Table.Th>Kategori</Table.Th>
                        <Table.Th className="text-right">Qty</Table.Th>
                        <Table.Th className="text-right">Omzet</Table.Th>
                        <Table.Th className="text-right">Profit</Table.Th>
                    </tr>
                </Table.Thead>
                <Table.Tbody>
                    {topSellingProducts.length > 0 ? (
                        topSellingProducts.map((item) => (
                            <tr key={item.product_id}>
                                <Table.Td>
                                    <div>
                                        <p className="font-semibold text-slate-900 dark:text-slate-100">
                                            {item.product_title}
                                        </p>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">
                                            {item.product_sku || "-"}
                                        </p>
                                    </div>
                                </Table.Td>
                                <Table.Td>{item.category_name || "-"}</Table.Td>
                                <Table.Td className="text-right">{item.qty_sold}</Table.Td>
                                <Table.Td className="text-right">{formatCurrency(item.revenue_total)}</Table.Td>
                                <Table.Td className="text-right">{formatCurrency(item.profit_total)}</Table.Td>
                            </tr>
                        ))
                    ) : (
                        <Table.Empty colSpan={5} message="Belum ada data top selling pada periode ini." />
                    )}
                </Table.Tbody>
            </Table>
        </Table.Card>
    );
}
