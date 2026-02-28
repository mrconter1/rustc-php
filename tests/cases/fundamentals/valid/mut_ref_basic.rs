// stdout: 6
fn inc(x: &mut i32) {
    *x = *x + 1;
}

fn main() {
    let mut n: i32 = 5;
    inc(&mut n);
    println!("{}", n);
}
