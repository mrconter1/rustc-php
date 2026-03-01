// stdout: 10
// stdout: 20
struct S {
    lo: u32,
    hi: u64,
}

fn main() {
    let s = S { lo: 10, hi: 20 };
    println!("{}", s.lo);
    println!("{}", s.hi);
}
