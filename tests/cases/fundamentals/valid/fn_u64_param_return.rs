// stdout: 22
fn double(n: u64) -> u64 {
    n + n
}

fn main() {
    let x: u64 = 10;
    let y = double(x);
    let z = double(1);
    println!("{}", y + z);
}
