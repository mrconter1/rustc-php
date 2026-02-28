// stdout: 15

fn main() {
    let base = 10;
    let add = |x: i32| x + base;
    println!("{}", add(5));
}
